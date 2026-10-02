<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Item;
use App\Models\PurchaseBill;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Purchasing\PurchasingException;
use App\Purchasing\PurchaseReturnService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReturnController extends Controller
{
    public function index(): Response
    {
        $returns = PurchaseReturn::with(['supplier:id,code,name'])
            ->orderByDesc('return_date')->orderByDesc('id')->paginate(20)
            ->through(fn (PurchaseReturn $r) => [
                'id' => $r->id, 'number' => $r->number, 'date' => $r->return_date->toDateString(),
                'supplier' => $r->supplier->name, 'total' => (float) $r->total, 'status' => $r->status,
            ]);

        return Inertia::render('purchase/returns/index', ['returns' => $returns]);
    }

    public function create(): Response
    {
        $bills = PurchaseBill::where('status', 'posted')->whereNotNull('goods_receipt_id')
            ->with(['lines' => fn ($q) => $q->whereNotNull('item_id')->whereColumn('returned_qty', '<', 'quantity'), 'goodsReceipt:id,warehouse_id'])
            ->orderByDesc('bill_date')->get()
            ->filter(fn ($b) => $b->lines->isNotEmpty())
            ->map(fn (PurchaseBill $b) => [
                'id' => $b->id, 'supplier_id' => $b->supplier_id, 'number' => $b->number,
                'warehouse_id' => $b->goodsReceipt?->warehouse_id,
                'lines' => $b->lines->map(fn ($l) => [
                    'id' => $l->id, 'item_id' => $l->item_id, 'remaining' => round((float) $l->quantity - (float) $l->returned_qty, 4), 'rate' => (float) $l->rate,
                ])->values(),
            ])->values();

        return Inertia::render('purchase/returns/create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('is_active', true)->where('tracks_inventory', true)->orderBy('code')->get(['id', 'code', 'name']),
            'bills' => $bills,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, PurchaseReturnService $service, TenantManager $tenant): RedirectResponse
    {
        $scoped = fn (string $t) => Rule::exists($t, 'id')->where('company_id', $tenant->id());
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', $scoped('suppliers')],
            'purchase_bill_id' => ['nullable', 'integer', $scoped('purchase_bills')],
            'warehouse_id' => ['required', 'integer', $scoped('warehouses')],
            'return_date' => ['required', 'date'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', $scoped('items')],
            'lines.*.purchase_bill_line_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $return = DB::transaction(function () use ($validated, $tenant) {
            $return = PurchaseReturn::create([
                'company_id' => $tenant->id(),
                'supplier_id' => $validated['supplier_id'],
                'purchase_bill_id' => $validated['purchase_bill_id'] ?? null,
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
                    'purchase_bill_line_id' => $line['purchase_bill_line_id'] ?? null,
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'rate' => 0,
                    'amount' => 0,
                ]);
            }

            return $return;
        });

        try {
            $service->post($return);
        } catch (PurchasingException|PostingException $e) {
            $return->lines()->delete();
            $return->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('purchase.returns.show', $return)->with('success', "Debit note {$return->number} posted.");
    }

    public function show(PurchaseReturn $return): Response
    {
        $return->load(['supplier:id,code,name', 'warehouse:id,code,name', 'bill:id,number', 'lines.item:id,code,name', 'reversalJournal:id,number']);

        return Inertia::render('purchase/returns/show', [
            'purchaseReturn' => [
                'id' => $return->id, 'number' => $return->number, 'date' => $return->return_date->toDateString(),
                'supplier' => $return->supplier->code.' — '.$return->supplier->name,
                'warehouse' => $return->warehouse->code.' — '.$return->warehouse->name,
                'bill' => $return->bill?->number, 'status' => $return->status,
                'subtotal' => (float) $return->subtotal, 'tax_amount' => (float) $return->tax_amount, 'total' => (float) $return->total,
                'journal_id' => $return->journal_id, 'reversal_journal_id' => $return->reversal_journal_id, 'reversal_journal' => $return->reversalJournal?->number,
                'can_reverse' => $return->isPosted(),
                'lines' => $return->lines->map(fn ($l) => ['item' => $l->item->code.' — '.$l->item->name, 'quantity' => (float) $l->quantity, 'cost' => (float) $l->cost]),
            ],
        ]);
    }

    public function reverse(PurchaseReturn $return, PurchaseReturnService $service): RedirectResponse
    {
        try {
            $service->reverse($return);
        } catch (PurchasingException|PostingException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return back()->with('success', 'Debit note reversed.');
    }
}
