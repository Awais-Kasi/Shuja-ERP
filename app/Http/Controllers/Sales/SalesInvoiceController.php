<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Ledger\PostingException;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Item;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Sales\SalesException;
use App\Sales\SalesInvoiceService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SalesInvoiceController extends Controller
{
    public function index(): Response
    {
        $invoices = SalesInvoice::query()
            ->with('customer:id,code,name')
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (SalesInvoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'date' => $i->invoice_date->toDateString(),
                'customer' => $i->customer->name,
                'status' => $i->status,
                'total' => (float) $i->total,
            ]);

        return Inertia::render('sales/invoices/index', ['invoices' => $invoices]);
    }

    public function create(Request $request, TenantManager $tenant): Response
    {
        $prefill = null;
        $soId = $request->integer('sales_order_id') ?: null;
        if ($soId) {
            $so = SalesOrder::with('lines.item:id,code,name')->find($soId);
            if ($so) {
                $prefill = [
                    'sales_order_id' => $so->id,
                    'number' => $so->number,
                    'customer_id' => $so->customer_id,
                    'warehouse_id' => $so->warehouse_id,
                    'lines' => $so->lines
                        ->filter(fn ($l) => (float) $l->delivered_qty < (float) $l->quantity)
                        ->map(fn ($l) => [
                            'sales_order_line_id' => $l->id,
                            'item_id' => $l->item_id,
                            'item_label' => $l->item->code.' — '.$l->item->name,
                            'quantity' => (float) $l->quantity - (float) $l->delivered_qty,
                            'rate' => (float) $l->rate,
                        ])->values(),
                ];
            }
        }

        return Inertia::render('sales/invoices/create', [
            'customers' => Customer::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('is_active', true)->where('is_sellable', true)->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
            'openOrders' => SalesOrder::where('status', 'confirmed')->with('customer:id,name')->orderByDesc('id')
                ->get(['id', 'number', 'customer_id'])->map(fn ($o) => ['id' => $o->id, 'label' => $o->number.' · '.$o->customer->name]),
            'prefill' => $prefill,
            'today' => now()->toDateString(),
            ...$this->currencyProps($tenant),
        ]);
    }

    /** Base currency plus the latest known rate for each foreign currency, for the form. */
    private function currencyProps(TenantManager $tenant): array
    {
        $base = $tenant->get()->base_currency;
        $rates = [$base => 1.0];
        foreach (ExchangeRate::where('base_code', $base)->orderByDesc('rate_date')->orderByDesc('id')->get(['quote_code', 'rate']) as $r) {
            $rates[$r->quote_code] ??= (float) $r->rate;
        }

        return ['baseCurrency' => $base, 'currencies' => array_keys($rates), 'rates' => $rates];
    }

    public function store(Request $request, SalesInvoiceService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'sales_order_id' => ['nullable', 'integer'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $base = $tenant->get()->base_currency;
        $currency = $validated['currency'] ?? $base;
        $fxRate = $currency === $base ? 1 : ($validated['fx_rate'] ?? 1);

        $invoice = DB::transaction(function () use ($validated, $tenant, $currency, $fxRate) {
            $invoice = SalesInvoice::create([
                'company_id' => $tenant->id(),
                'customer_id' => $validated['customer_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'sales_order_id' => $validated['sales_order_id'] ?? null,
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'currency' => $currency,
                'fx_rate' => $fxRate,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'status' => 'draft',
                'memo' => $validated['memo'] ?? null,
            ]);

            foreach ($validated['lines'] as $line) {
                $invoice->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'],
                    'sales_order_line_id' => $line['sales_order_line_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'amount' => round($line['quantity'] * $line['rate'], 4),
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $invoice;
        });

        try {
            $service->post($invoice);
        } catch (SalesException|InventoryException|PostingException $e) {
            $invoice->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('sales.invoices.show', $invoice)->with('success', "Invoice {$invoice->number} posted.");
    }

    /** Print-ready A4 invoice document (browser Print / Save-PDF, no PDF library). */
    public function print(SalesInvoice $invoice, TenantManager $tenant): Response
    {
        $invoice->load(['customer', 'lines.item:id,code,name']);
        $company = $tenant->get();

        return Inertia::render('sales/invoices/print', [
            'company' => [
                'name' => $company?->name,
                'legal_name' => $company?->legal_name,
                'address' => $company?->address,
                'tax_no' => $company?->tax_registration_no,
                'base_currency' => $company?->base_currency ?? 'PKR',
            ],
            'invoice' => [
                'number' => $invoice->number,
                'date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'status' => $invoice->status,
                'currency' => $invoice->currency ?: ($company?->base_currency ?? 'PKR'),
                'fx_rate' => (float) $invoice->fx_rate,
                'subtotal' => (float) $invoice->subtotal,
                'tax_amount' => (float) $invoice->tax_amount,
                'total' => (float) $invoice->total,
                'amount_paid' => (float) $invoice->amount_paid,
                'memo' => $invoice->memo,
                'customer' => [
                    'code' => $invoice->customer->code,
                    'name' => $invoice->customer->name,
                    'legal_name' => $invoice->customer->legal_name,
                    'address' => $invoice->customer->address,
                    'tax_no' => $invoice->customer->tax_registration_no,
                    'email' => $invoice->customer->email,
                    'phone' => $invoice->customer->phone,
                ],
                'lines' => $invoice->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'description' => $l->description,
                    'quantity' => (float) $l->quantity,
                    'rate' => (float) $l->rate,
                    'amount' => (float) $l->amount,
                ]),
            ],
        ]);
    }

    public function show(SalesInvoice $invoice): Response
    {
        $invoice->load(['customer:id,code,name', 'warehouse:id,code,name', 'lines.item:id,code,name']);

        return Inertia::render('sales/invoices/show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'customer' => $invoice->customer->code.' — '.$invoice->customer->name,
                'warehouse' => $invoice->warehouse->code.' — '.$invoice->warehouse->name,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'fx_rate' => (float) $invoice->fx_rate,
                'subtotal' => (float) $invoice->subtotal,
                'tax_amount' => (float) $invoice->tax_amount,
                'total' => (float) $invoice->total,
                'cogs_total' => (float) $invoice->cogs_total,
                'journal_id' => $invoice->journal_id,
                'lines' => $invoice->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'quantity' => (float) $l->quantity,
                    'rate' => (float) $l->rate,
                    'amount' => (float) $l->amount,
                ]),
            ],
        ]);
    }
}
