<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Customer;
use App\Models\Item;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use App\Sales\SalesException;
use App\Sales\SalesReturnService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesReturnController extends Controller
{
    public function index(): Response
    {
        $returns = SalesReturn::with(['customer:id,code,name'])
            ->orderByDesc('return_date')->orderByDesc('id')->paginate(20)
            ->through(fn (SalesReturn $r) => [
                'id' => $r->id, 'number' => $r->number, 'date' => $r->return_date->toDateString(),
                'customer' => $r->customer->name, 'total' => (float) $r->total, 'status' => $r->status,
            ]);

        return Inertia::render('sales/returns/index', ['returns' => $returns]);
    }

    public function create(): Response
    {
        $invoices = SalesInvoice::where('status', 'posted')->with(['lines' => fn ($q) => $q->whereColumn('returned_qty', '<', 'quantity')])
            ->orderByDesc('invoice_date')->get()
            ->filter(fn ($inv) => $inv->lines->isNotEmpty())
            ->map(fn (SalesInvoice $inv) => [
                'id' => $inv->id, 'customer_id' => $inv->customer_id, 'number' => $inv->number, 'warehouse_id' => $inv->warehouse_id,
                'lines' => $inv->lines->map(fn ($l) => [
                    'id' => $l->id, 'item_id' => $l->item_id, 'remaining' => round((float) $l->quantity - (float) $l->returned_qty, 4),
                    'rate' => (float) $l->rate, 'unit_cost' => (float) $l->quantity > 0 ? round((float) $l->cost / (float) $l->quantity, 4) : 0,
                ])->values(),
            ])->values();

        return Inertia::render('sales/returns/create', [
            'customers' => Customer::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('is_active', true)->where('tracks_inventory', true)->orderBy('code')->get(['id', 'code', 'name']),
            'invoices' => $invoices,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, SalesReturnService $service, TenantManager $tenant): RedirectResponse
    {
        $scoped = fn (string $t) => Rule::exists($t, 'id')->where('company_id', $tenant->id());
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', $scoped('customers')],
            'sales_invoice_id' => ['nullable', 'integer', $scoped('sales_invoices')],
            'warehouse_id' => ['required', 'integer', $scoped('warehouses')],
            'return_date' => ['required', 'date'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', $scoped('items')],
            'lines.*.sales_invoice_line_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
        ]);

        $return = DB::transaction(function () use ($validated, $tenant) {
            $return = SalesReturn::create([
                'company_id' => $tenant->id(),
                'customer_id' => $validated['customer_id'],
                'sales_invoice_id' => $validated['sales_invoice_id'] ?? null,
                'warehouse_id' => $validated['warehouse_id'],
                'return_date' => $validated['return_date'],
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'memo' => $validated['memo'] ?? null,
                'status' => 'draft',
                'created_by' => optional(auth()->user())->id,
            ]);
            foreach ($validated['lines'] as $line) {
                $return->lines()->create([
                    'company_id' => $tenant->id(),
                    'sales_invoice_line_id' => $line['sales_invoice_line_id'] ?? null,
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'amount' => round((float) $line['quantity'] * (float) $line['rate'], 4),
                ]);
            }

            return $return;
        });

        try {
            $service->post($return);
        } catch (SalesException|PostingException $e) {
            $return->lines()->delete();
            $return->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('sales.returns.show', $return)->with('success', "Credit note {$return->number} posted.");
    }

    public function show(SalesReturn $return): Response
    {
        $return->load(['customer:id,code,name', 'warehouse:id,code,name', 'invoice:id,number', 'lines.item:id,code,name', 'reversalJournal:id,number']);

        return Inertia::render('sales/returns/show', [
            'salesReturn' => [
                'id' => $return->id, 'number' => $return->number, 'date' => $return->return_date->toDateString(),
                'customer' => $return->customer->code.' — '.$return->customer->name,
                'warehouse' => $return->warehouse->code.' — '.$return->warehouse->name,
                'invoice' => $return->invoice?->number, 'status' => $return->status,
                'subtotal' => (float) $return->subtotal, 'tax_amount' => (float) $return->tax_amount, 'total' => (float) $return->total,
                'journal_id' => $return->journal_id, 'reversal_journal_id' => $return->reversal_journal_id, 'reversal_journal' => $return->reversalJournal?->number,
                'can_reverse' => $return->isPosted(),
                'lines' => $return->lines->map(fn ($l) => ['item' => $l->item->code.' — '.$l->item->name, 'quantity' => (float) $l->quantity, 'rate' => (float) $l->rate, 'amount' => (float) $l->amount]),
            ],
        ]);
    }

    public function reverse(SalesReturn $return, SalesReturnService $service): RedirectResponse
    {
        try {
            $service->reverse($return);
        } catch (SalesException|PostingException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return back()->with('success', 'Credit note reversed.');
    }
}
