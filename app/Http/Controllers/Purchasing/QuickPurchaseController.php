<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Payment;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Purchasing\GoodsReceiptService;
use App\Purchasing\PurchaseBillService;
use App\Purchasing\PurchasingException;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One-screen "Quick Purchase": receive the goods (GRN), book the supplier bill
 * (AP) and — if money was paid now — record the supplier payment, all in one
 * submit. Mirrors Quick Sale on the buying side, reusing the existing services.
 */
class QuickPurchaseController extends Controller
{
    public function create(TenantManager $tenant): Response
    {
        return Inertia::render('purchase/quick', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('is_active', true)->where('tracks_inventory', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'standard_cost'])
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'rate' => (float) $i->standard_cost]),
            'accounts' => Account::whereIn('control_type', ['cash', 'bank'])->where('is_group', false)
                ->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'control_type']),
            'today' => now()->toDateString(),
            'baseCurrency' => $tenant->get()->base_currency,
        ]);
    }

    public function store(
        Request $request,
        GoodsReceiptService $receipts,
        PurchaseBillService $bills,
        PaymentService $payments,
        TenantManager $tenant,
    ): RedirectResponse {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $tenant->id())],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $tenant->id())],
            'purchase_date' => ['required', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['nullable', 'integer', 'required_with:amount_paid', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())],
        ]);

        // 1) Receive the goods into stock.
        $grn = DB::transaction(function () use ($validated, $tenant) {
            $grn = GoodsReceipt::create([
                'company_id' => $tenant->id(),
                'supplier_id' => $validated['supplier_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'receipt_date' => $validated['purchase_date'],
                'supplier_dn_no' => $validated['supplier_invoice_no'] ?? null,
                'memo' => $validated['memo'] ?? null,
                'status' => 'draft',
            ]);
            foreach ($validated['lines'] as $line) {
                $grn->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'amount' => round($line['quantity'] * $line['rate'], 4),
                ]);
            }

            return $grn;
        });

        try {
            $receipts->post($grn);
        } catch (PurchasingException|InventoryException|PostingException $e) {
            $grn->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        // 2) Book the supplier bill (clears GRNI, credits Accounts Payable).
        $bill = DB::transaction(function () use ($validated, $tenant, $grn) {
            $bill = PurchaseBill::create([
                'company_id' => $tenant->id(),
                'supplier_id' => $validated['supplier_id'],
                'goods_receipt_id' => $grn->id,
                'bill_date' => $validated['purchase_date'],
                'supplier_invoice_no' => $validated['supplier_invoice_no'] ?? null,
                'currency' => $tenant->get()->base_currency,
                'fx_rate' => 1,
                'tax_amount' => 0,
                'status' => 'draft',
                'memo' => $validated['memo'] ?? null,
            ]);
            foreach ($validated['lines'] as $line) {
                $bill->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'amount' => round($line['quantity'] * $line['rate'], 4),
                ]);
            }

            return $bill;
        });

        try {
            $bills->post($bill);
        } catch (PurchasingException|PostingException $e) {
            $bill->delete();

            return redirect()->route('purchase.receipts.show', $grn)
                ->with('error', "Maal to aa gaya (GRN {$grn->number}), lekin bill nahi bana ({$e->getMessage()}). Baraye meharbani bill alag se banayein.");
        }

        // 3) Optionally pay the supplier now, against this bill.
        $paid = round((float) ($validated['amount_paid'] ?? 0), 2);
        if ($paid > 0.005 && ! empty($validated['account_id'])) {
            try {
                $allocation = min($paid, round((float) $bill->total, 2));
                $payment = DB::transaction(function () use ($validated, $paid, $allocation, $bill, $tenant) {
                    $payment = Payment::create([
                        'company_id' => $tenant->id(),
                        'direction' => 'pay',
                        'party_type' => $bill->supplier->getMorphClass(),
                        'party_id' => $bill->supplier_id,
                        'payment_date' => $validated['purchase_date'],
                        'account_id' => $validated['account_id'],
                        'amount' => $paid,
                        'currency' => $tenant->get()->base_currency,
                        'fx_rate' => 1,
                        'memo' => "Quick purchase {$bill->number}",
                        'status' => 'draft',
                        'created_by' => optional(auth()->user())->id,
                    ]);
                    if ($allocation > 0.005) {
                        $payment->allocations()->create([
                            'company_id' => $tenant->id(),
                            'allocatable_type' => (new PurchaseBill)->getMorphClass(),
                            'allocatable_id' => $bill->id,
                            'amount' => $allocation,
                        ]);
                    }

                    return $payment;
                });
                $payments->post($payment);
            } catch (PaymentException|PostingException $e) {
                return redirect()->route('purchase.bills.show', $bill)
                    ->with('error', "Bill {$bill->number} ban gaya, lekin payment nahi laga ({$e->getMessage()}). Supplier Payments se alag se dein.");
            }
        }

        return redirect()->route('purchase.bills.show', $bill)->with('success', "Khareed {$bill->number} mukammal.");
    }
}
