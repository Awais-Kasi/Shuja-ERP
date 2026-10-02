<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\GoodsReceipt;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Purchasing\PurchaseBillService;
use App\Purchasing\PurchasingException;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseBillController extends Controller
{
    public function index(): Response
    {
        $bills = PurchaseBill::query()
            ->with('supplier:id,code,name')
            ->orderByDesc('bill_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (PurchaseBill $b) => [
                'id' => $b->id,
                'number' => $b->number,
                'supplier_invoice_no' => $b->supplier_invoice_no,
                'date' => $b->bill_date->toDateString(),
                'supplier' => $b->supplier->name,
                'status' => $b->status,
                'total' => (float) $b->total,
            ]);

        return Inertia::render('purchase/bills/index', ['bills' => $bills]);
    }

    public function create(Request $request, TenantManager $tenant): Response
    {
        $prefill = null;
        $grnId = $request->integer('goods_receipt_id') ?: null;
        if ($grnId) {
            $grn = GoodsReceipt::with('lines.item:id,code,name')->find($grnId);
            if ($grn) {
                $prefill = [
                    'goods_receipt_id' => $grn->id,
                    'supplier_id' => $grn->supplier_id,
                    'lines' => $grn->lines->map(fn ($l) => [
                        'item_id' => $l->item_id,
                        'item_label' => $l->item->code.' — '.$l->item->name,
                        'quantity' => (float) $l->quantity,
                        'rate' => (float) $l->rate,
                    ])->values(),
                ];
            }
        }

        return Inertia::render('purchase/bills/create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'unbilledReceipts' => GoodsReceipt::where('status', 'posted')->where('billed', false)->with('supplier:id,name')
                ->orderByDesc('id')->get(['id', 'number', 'supplier_id'])
                ->map(fn ($g) => ['id' => $g->id, 'label' => $g->number.' · '.$g->supplier->name]),
            'accounts' => Account::where('is_group', false)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name]),
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

    public function store(Request $request, PurchaseBillService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'goods_receipt_id' => ['nullable', 'integer'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.rate' => ['required', 'numeric'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $base = $tenant->get()->base_currency;
        $currency = $validated['currency'] ?? $base;
        $fxRate = $currency === $base ? 1 : ($validated['fx_rate'] ?? 1);

        $bill = DB::transaction(function () use ($validated, $tenant, $currency, $fxRate) {
            $bill = PurchaseBill::create([
                'company_id' => $tenant->id(),
                'supplier_id' => $validated['supplier_id'],
                'goods_receipt_id' => $validated['goods_receipt_id'] ?? null,
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'] ?? null,
                'supplier_invoice_no' => $validated['supplier_invoice_no'] ?? null,
                'currency' => $currency,
                'fx_rate' => $fxRate,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'status' => 'draft',
                'memo' => $validated['memo'] ?? null,
            ]);

            foreach ($validated['lines'] as $line) {
                $bill->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'] ?? null,
                    'account_id' => $line['account_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'amount' => round($line['quantity'] * $line['rate'], 4),
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $bill;
        });

        try {
            $service->post($bill);
        } catch (PurchasingException|PostingException $e) {
            $bill->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('purchase.bills.show', $bill)->with('success', "Bill {$bill->number} posted.");
    }

    public function show(PurchaseBill $bill): Response
    {
        $bill->load(['supplier:id,code,name', 'lines.item:id,code,name', 'lines.account:id,code,name']);

        return Inertia::render('purchase/bills/show', [
            'bill' => [
                'id' => $bill->id,
                'number' => $bill->number,
                'supplier_invoice_no' => $bill->supplier_invoice_no,
                'date' => $bill->bill_date->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'supplier' => $bill->supplier->code.' — '.$bill->supplier->name,
                'status' => $bill->status,
                'currency' => $bill->currency,
                'fx_rate' => (float) $bill->fx_rate,
                'subtotal' => (float) $bill->subtotal,
                'tax_amount' => (float) $bill->tax_amount,
                'total' => (float) $bill->total,
                'journal_id' => $bill->journal_id,
                'lines' => $bill->lines->map(fn ($l) => [
                    'label' => $l->item ? $l->item->code.' — '.$l->item->name : ($l->account ? $l->account->code.' — '.$l->account->name : '—'),
                    'quantity' => (float) $l->quantity,
                    'rate' => (float) $l->rate,
                    'amount' => (float) $l->amount,
                ]),
            ],
        ]);
    }
}
