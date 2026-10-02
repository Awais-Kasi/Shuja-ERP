<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Payment;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Pay Co', 'code' => 'PAY', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        foreach ([['1102', 'Bank', 'asset', 'bank'], ['1110', 'AR', 'asset', 'ar'], ['2110', 'AP', 'liability', 'ap']] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }
        $this->wh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG', 'type' => 'warehouse']);
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function customer(): Customer
    {
        return Customer::create(['company_id' => $this->company->id, 'code' => 'C'.uniqid(), 'name' => 'Cust', 'receivable_account_id' => $this->acct('1110')]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['company_id' => $this->company->id, 'code' => 'S'.uniqid(), 'name' => 'Supp', 'payable_account_id' => $this->acct('2110')]);
    }

    private function invoice(Customer $c, float $total): SalesInvoice
    {
        static $n = 0;
        $n++;

        return SalesInvoice::create(['company_id' => $this->company->id, 'customer_id' => $c->id, 'warehouse_id' => $this->wh->id, 'number' => 'INV-'.$n, 'invoice_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'amount_paid' => 0, 'cogs_total' => 0]);
    }

    private function bill(Supplier $s, float $total): PurchaseBill
    {
        static $n = 0;
        $n++;

        return PurchaseBill::create(['company_id' => $this->company->id, 'supplier_id' => $s->id, 'number' => 'BILL-'.$n, 'bill_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'amount_paid' => 0]);
    }

    /** @param array<int, array{0:object,1:float}> $allocations [document, amount] */
    private function pay(string $direction, object $party, float $amount, int $accountId, array $allocations = []): Payment
    {
        $payment = Payment::create([
            'company_id' => $this->company->id, 'direction' => $direction,
            'party_type' => $party->getMorphClass(), 'party_id' => $party->id,
            'payment_date' => now()->toDateString(), 'account_id' => $accountId, 'amount' => $amount, 'status' => 'draft',
        ]);
        foreach ($allocations as [$doc, $amt]) {
            $payment->allocations()->create(['company_id' => $this->company->id, 'allocatable_type' => $doc->getMorphClass(), 'allocatable_id' => $doc->id, 'amount' => $amt]);
        }

        return app(PaymentService::class)->post($payment);
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    public function test_customer_receipt_debits_bank_credits_ar_and_settles_invoice(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 10000);

        $this->pay('receive', $c, 6000, $this->acct('1102'), [[$inv, 6000]]);

        $this->assertEqualsWithDelta(6000.0, $this->gl('1102'), 0.01);   // Dr bank
        $this->assertEqualsWithDelta(-6000.0, $this->gl('1110'), 0.01);  // Cr AR
        $inv->refresh();
        $this->assertEqualsWithDelta(6000.0, (float) $inv->amount_paid, 0.01);
        $this->assertEqualsWithDelta(4000.0, $inv->outstanding(), 0.01);
        $this->assertSame('partial', $inv->settlementStatus());
    }

    public function test_full_receipt_marks_invoice_paid(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 10000);
        $this->pay('receive', $c, 10000, $this->acct('1102'), [[$inv, 10000]]);

        $this->assertSame('paid', $inv->refresh()->settlementStatus());
        $this->assertEqualsWithDelta(0.0, $inv->outstanding(), 0.01);
    }

    public function test_supplier_payment_debits_ap_credits_bank(): void
    {
        $s = $this->supplier();
        $bill = $this->bill($s, 8000);
        $this->pay('pay', $s, 8000, $this->acct('1102'), [[$bill, 8000]]);

        $this->assertEqualsWithDelta(8000.0, $this->gl('2110'), 0.01);   // Dr AP
        $this->assertEqualsWithDelta(-8000.0, $this->gl('1102'), 0.01);  // Cr bank
        $this->assertSame('paid', $bill->refresh()->settlementStatus());
    }

    public function test_on_account_receipt_posts_full_amount_and_tracks_unapplied(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 3000);
        $payment = $this->pay('receive', $c, 5000, $this->acct('1102'), [[$inv, 3000]]);

        $this->assertEqualsWithDelta(5000.0, $this->gl('1102'), 0.01);   // full 5,000 to bank
        $this->assertEqualsWithDelta(-5000.0, $this->gl('1110'), 0.01);  // full 5,000 off AR
        $this->assertEqualsWithDelta(2000.0, $payment->refresh()->load('allocations')->unappliedAmount(), 0.01);
        $this->assertSame('paid', $inv->refresh()->settlementStatus());
    }

    public function test_cannot_allocate_more_than_a_document_outstanding(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 3000);

        $this->expectException(PaymentException::class);
        $this->pay('receive', $c, 5000, $this->acct('1102'), [[$inv, 5000]]); // 5,000 > 3,000 outstanding
    }

    public function test_allocations_cannot_exceed_the_amount(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 10000);

        $this->expectException(PaymentException::class);
        $this->pay('receive', $c, 1000, $this->acct('1102'), [[$inv, 2000]]); // alloc 2,000 > amount 1,000
    }

    public function test_cannot_allocate_another_partys_document(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $invB = $this->invoice($b, 5000);

        $this->expectException(PaymentException::class);
        $this->pay('receive', $a, 5000, $this->acct('1102'), [[$invB, 5000]]); // B's invoice on A's receipt
    }

    public function test_receipt_requires_a_customer_party(): void
    {
        $s = $this->supplier();
        $this->expectException(PaymentException::class);
        $this->pay('receive', $s, 1000, $this->acct('1102')); // supplier on a receipt
    }

    public function test_reversing_a_receipt_reopens_the_invoice(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 10000);
        $payment = $this->pay('receive', $c, 10000, $this->acct('1102'), [[$inv, 10000]]);
        $this->assertSame('paid', $inv->refresh()->settlementStatus());

        app(PaymentService::class)->reverse($payment->refresh());

        $this->assertSame('reversed', $payment->refresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $inv->refresh()->amount_paid, 0.01);
        $this->assertSame('unpaid', $inv->settlementStatus());
        $this->assertEqualsWithDelta(0.0, $this->gl('1102'), 0.01); // receipt + reversal net to zero
    }
}
