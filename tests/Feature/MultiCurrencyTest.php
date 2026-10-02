<?php

namespace Tests\Feature;

use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Payment;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Purchasing\PurchaseBillService;
use App\Reports\AgingReports;
use App\Sales\SalesInvoiceService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 7 — foreign-currency documents. Revenue, tax and the AR/AP balance are carried in
 * the document currency at its fx_rate (base per unit); stock/COGS stay in base. Settlement
 * at a different rate books the realised FX gain/loss so the subledger clears to nil.
 */
class MultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $wh;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'FX Co', 'code' => 'FX', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        foreach ([
            ['1102', 'Bank', 'asset', 'bank'],
            ['1110', 'AR', 'asset', 'ar'],
            ['1123', 'Inventory', 'asset', 'inventory'],
            ['1130', 'Input Tax', 'asset', 'tax'],
            ['2110', 'AP', 'liability', 'ap'],
            ['2120', 'Output Tax', 'liability', 'tax'],
            ['4100', 'Sales', 'income', null],
            ['4400', 'FX Gain/(Loss)', 'income', null],
            ['5100', 'COGS', 'expense', null],
            ['6100', 'Freight', 'expense', null],
        ] as [$code, $name, $type, $control]) {
            $this->acc[$code] = Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }

        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->wh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG']);
        $this->item = Item::create([
            'company_id' => $this->company->id, 'code' => 'CAB', 'name' => 'Cabinet', 'uom_id' => $uom->id,
            'inventory_account_id' => $this->acc['1123']->id, 'income_account_id' => $this->acc['4100']->id,
            'cogs_account_id' => $this->acc['5100']->id, 'valuation_method' => 'weighted_average',
        ]);
        // Seed stock at base cost 12,000/unit so COGS is a clean base number.
        app(InventoryService::class)->receive($this->item, $this->wh, 50, 12000);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    /** Foreign balance (debit − credit) on an account, in document currency. */
    private function foreignBal(string $code, string $currency): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->where('l.currency', $currency)->sum(DB::raw('l.debit - l.credit'));
    }

    private function customer(): Customer
    {
        return Customer::create(['company_id' => $this->company->id, 'code' => 'C'.uniqid(), 'name' => 'Cust', 'receivable_account_id' => $this->acc['1110']->id]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['company_id' => $this->company->id, 'code' => 'S'.uniqid(), 'name' => 'Supp', 'payable_account_id' => $this->acc['2110']->id]);
    }

    /** A posted USD invoice for 10 units @ $100 (=$1,000) at the given rate. */
    private function usdInvoice(Customer $c, float $rate): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id, 'customer_id' => $c->id, 'warehouse_id' => $this->wh->id,
            'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'currency' => 'USD', 'fx_rate' => $rate, 'tax_amount' => 0, 'status' => 'draft',
        ]);
        $invoice->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => 10, 'rate' => 100, 'amount' => 1000]);

        return app(SalesInvoiceService::class)->post($invoice->fresh());
    }

    private function receipt(Customer $c, float $amount, string $currency, float $rate, array $alloc): Payment
    {
        $p = Payment::create([
            'company_id' => $this->company->id, 'direction' => 'receive',
            'party_type' => $c->getMorphClass(), 'party_id' => $c->id, 'payment_date' => now()->toDateString(),
            'account_id' => $this->acc['1102']->id, 'amount' => $amount, 'currency' => $currency, 'fx_rate' => $rate, 'status' => 'draft',
        ]);
        foreach ($alloc as [$doc, $amt]) {
            $p->allocations()->create(['company_id' => $this->company->id, 'allocatable_type' => $doc->getMorphClass(), 'allocatable_id' => $doc->id, 'amount' => $amt]);
        }

        return app(PaymentService::class)->post($p);
    }

    public function test_foreign_invoice_books_ar_and_revenue_in_document_currency(): void
    {
        $inv = $this->usdInvoice($this->customer(), 280);

        $ar = $inv->journal->lines->firstWhere('account_id', $this->acc['1110']->id);
        $this->assertSame('USD', $ar->currency);
        $this->assertEquals('1000.0000', $ar->debit);              // foreign face value
        $this->assertEquals('280000.0000', $ar->base_debit);       // converted at 280
        $rev = $inv->journal->lines->firstWhere('account_id', $this->acc['4100']->id);
        $this->assertEquals('280000.0000', $rev->base_credit);     // revenue booked in base at 280
        // Stock/COGS stay in base regardless of the invoice currency.
        $this->assertEquals('120000.0000', $inv->journal->lines->firstWhere('account_id', $this->acc['5100']->id)->base_debit);
        $this->assertEquals('120000.0000', $inv->journal->lines->firstWhere('account_id', $this->acc['1123']->id)->base_credit);
        // The document itself stores the foreign face amount.
        $this->assertEquals('1000.0000', $inv->total);
    }

    public function test_receipt_at_higher_rate_books_realised_fx_gain_and_clears_ar(): void
    {
        $c = $this->customer();
        $inv = $this->usdInvoice($c, 280);

        $this->receipt($c, 1000, 'USD', 285, [[$inv, 1000]]); // paid when USD strengthened

        $this->assertEqualsWithDelta(285000.0, $this->gl('1102'), 0.01);  // bank in at 285
        $this->assertEqualsWithDelta(-5000.0, $this->gl('4400'), 0.01);   // 1,000 × (285−280) gain (credit)
        $this->assertEqualsWithDelta(0.0, $this->gl('1110'), 0.01);       // AR cleared in base
        $this->assertEqualsWithDelta(0.0, $this->foreignBal('1110', 'USD'), 0.01); // and in USD
        $this->assertSame('paid', $inv->refresh()->settlementStatus());
    }

    public function test_receipt_at_lower_rate_books_realised_fx_loss(): void
    {
        $c = $this->customer();
        $inv = $this->usdInvoice($c, 280);

        $this->receipt($c, 1000, 'USD', 275, [[$inv, 1000]]); // paid when USD weakened

        $this->assertEqualsWithDelta(275000.0, $this->gl('1102'), 0.01);
        $this->assertEqualsWithDelta(5000.0, $this->gl('4400'), 0.01);    // loss (debit)
        $this->assertEqualsWithDelta(0.0, $this->gl('1110'), 0.01);
        $this->assertSame('paid', $inv->refresh()->settlementStatus());
    }

    public function test_foreign_bill_and_payment_realise_fx(): void
    {
        $s = $this->supplier();
        $bill = PurchaseBill::create([
            'company_id' => $this->company->id, 'supplier_id' => $s->id, 'supplier_invoice_no' => 'SI-1',
            'bill_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'currency' => 'USD', 'fx_rate' => 280, 'tax_amount' => 0, 'status' => 'draft',
        ]);
        $bill->lines()->create(['company_id' => $this->company->id, 'account_id' => $this->acc['6100']->id, 'quantity' => 1, 'rate' => 1000, 'amount' => 1000]);
        app(PurchaseBillService::class)->post($bill->fresh());

        $ap = $bill->refresh()->journal->lines->firstWhere('account_id', $this->acc['2110']->id);
        $this->assertSame('USD', $ap->currency);
        $this->assertEquals('280000.0000', $ap->base_credit);
        $this->assertEqualsWithDelta(280000.0, $this->gl('6100'), 0.01); // freight expensed at 280

        $pay = Payment::create([
            'company_id' => $this->company->id, 'direction' => 'pay', 'party_type' => $s->getMorphClass(), 'party_id' => $s->id,
            'payment_date' => now()->toDateString(), 'account_id' => $this->acc['1102']->id, 'amount' => 1000, 'currency' => 'USD', 'fx_rate' => 277, 'status' => 'draft',
        ]);
        $pay->allocations()->create(['company_id' => $this->company->id, 'allocatable_type' => $bill->getMorphClass(), 'allocatable_id' => $bill->id, 'amount' => 1000]);
        app(PaymentService::class)->post($pay);

        $this->assertEqualsWithDelta(-277000.0, $this->gl('1102'), 0.01); // bank out at 277
        $this->assertEqualsWithDelta(-3000.0, $this->gl('4400'), 0.01);   // 1,000 × (280−277) gain
        $this->assertEqualsWithDelta(0.0, $this->gl('2110'), 0.01);       // AP cleared
        $this->assertSame('paid', $bill->refresh()->settlementStatus());
    }

    public function test_cannot_settle_a_foreign_document_with_a_base_payment(): void
    {
        $c = $this->customer();
        $inv = $this->usdInvoice($c, 280);

        $this->expectException(PaymentException::class);
        $this->receipt($c, 280000, 'PKR', 1, [[$inv, 1000]]); // base receipt against a USD invoice
    }

    public function test_aging_converts_foreign_outstanding_to_base_and_flags_exposure(): void
    {
        $c = $this->customer();
        $this->usdInvoice($c, 280); // open USD 1,000 invoice

        $r = app(AgingReports::class)->receivables(now()->toDateString());

        $this->assertTrue($r['has_foreign']);
        $this->assertEqualsWithDelta(280000.0, $r['totals']['total'], 0.01); // base-converted
        $this->assertStringContainsString('USD 1,000.00', $r['rows'][0]['currencies']);
    }
}
