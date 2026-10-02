<?php

namespace Tests\Feature;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\PurchaseBill;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Reports\TaxReports;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaxReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $wh;

    private Customer $customer;

    private Supplier $supplier;

    private string $from;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = Carbon::now()->startOfMonth()->toDateString();
        $this->to = Carbon::now()->endOfMonth()->toDateString();

        $this->company = Company::create(['name' => 'Tax Co', 'code' => 'TAX', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);

        Account::create(['company_id' => $this->company->id, 'code' => '2120', 'name' => 'Output Tax', 'type' => 'liability', 'control_type' => 'tax']);
        Account::create(['company_id' => $this->company->id, 'code' => '1130', 'name' => 'Input Tax', 'type' => 'asset', 'control_type' => 'tax']);
        Account::create(['company_id' => $this->company->id, 'code' => '1101', 'name' => 'Cash', 'type' => 'asset', 'control_type' => 'cash']);

        $this->wh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG', 'type' => 'warehouse']);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C', 'name' => 'Cust']);
        $this->supplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'S', 'name' => 'Supp']);
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    public function test_output_input_and_net_tax(): void
    {
        SalesInvoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'number' => 'INV-1', 'invoice_date' => $this->to, 'status' => 'posted', 'subtotal' => 10000, 'tax_amount' => 1000, 'total' => 11000, 'amount_paid' => 0, 'cogs_total' => 0]);
        SalesInvoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'number' => 'INV-2', 'invoice_date' => $this->to, 'status' => 'posted', 'subtotal' => 5000, 'tax_amount' => 500, 'total' => 5500, 'amount_paid' => 0, 'cogs_total' => 0]);
        SalesReturn::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'number' => 'CRN-1', 'return_date' => $this->to, 'status' => 'posted', 'subtotal' => 2000, 'tax_amount' => 200, 'total' => 2200, 'cogs_total' => 0]);

        PurchaseBill::create(['company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'number' => 'BILL-1', 'bill_date' => $this->to, 'status' => 'posted', 'subtotal' => 8000, 'tax_amount' => 800, 'total' => 8800, 'amount_paid' => 0]);
        PurchaseReturn::create(['company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'number' => 'DRN-1', 'return_date' => $this->to, 'status' => 'posted', 'subtotal' => 1000, 'tax_amount' => 100, 'total' => 1100]);

        $r = app(TaxReports::class)->salesTax($this->from, $this->to);

        $this->assertEqualsWithDelta(1300, $r['summary']['output_tax'], 0.01);       // 1000 + 500 - 200
        $this->assertEqualsWithDelta(700, $r['summary']['input_tax'], 0.01);         // 800 - 100
        $this->assertEqualsWithDelta(600, $r['summary']['net_payable'], 0.01);       // 1300 - 700
        $this->assertEqualsWithDelta(13000, $r['summary']['taxable_sales'], 0.01);   // 10000 + 5000 - 2000
        $this->assertCount(3, $r['output']);
        $this->assertCount(2, $r['input']);
    }

    public function test_ledger_cross_check_reads_the_tax_accounts(): void
    {
        // A posted journal collecting output tax and paying input tax.
        app(PostingEngine::class)->post(new LedgerEntry(entryDate: $this->to, type: 'manual', lines: [
            LedgerLine::debit($this->acct('1101'), 1000),
            LedgerLine::credit($this->acct('2120'), 1000),
        ]));
        app(PostingEngine::class)->post(new LedgerEntry(entryDate: $this->to, type: 'manual', lines: [
            LedgerLine::debit($this->acct('1130'), 400),
            LedgerLine::credit($this->acct('1101'), 400),
        ]));

        $r = app(TaxReports::class)->salesTax($this->from, $this->to);

        $this->assertEqualsWithDelta(1000, $r['summary']['ledger_output_tax'], 0.01);
        $this->assertEqualsWithDelta(400, $r['summary']['ledger_input_tax'], 0.01);
    }

    public function test_consignment_settlements_are_included_in_output_tax(): void
    {
        $con = Warehouse::create(['company_id' => $this->company->id, 'code' => 'CON', 'name' => 'Consignment', 'type' => 'consignment']);
        $dispatch = \App\Models\ConsignmentDispatch::create(['company_id' => $this->company->id, 'from_warehouse_id' => $this->wh->id, 'to_warehouse_id' => $con->id, 'agent_customer_id' => $this->customer->id, 'dispatch_date' => $this->to, 'status' => 'posted']);
        \App\Models\ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $dispatch->id, 'consignment_warehouse_id' => $con->id, 'agent_customer_id' => $this->customer->id, 'number' => 'CST-1', 'settlement_date' => $this->to, 'subtotal' => 5000, 'tax_amount' => 850, 'total' => 5850, 'status' => 'posted']);

        $r = app(TaxReports::class)->salesTax($this->from, $this->to);

        $this->assertEqualsWithDelta(850, $r['summary']['output_tax'], 0.01);
        $this->assertTrue(collect($r['output'])->contains(fn ($l) => $l['type'] === 'Consignment' && abs($l['tax'] - 850) < 0.01));
    }

    public function test_documents_outside_the_period_are_excluded(): void
    {
        SalesInvoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'number' => 'OLD', 'invoice_date' => Carbon::now()->subMonths(3)->toDateString(), 'status' => 'posted', 'subtotal' => 9999, 'tax_amount' => 999, 'total' => 10998, 'amount_paid' => 0, 'cogs_total' => 0]);

        $r = app(TaxReports::class)->salesTax($this->from, $this->to);
        $this->assertEqualsWithDelta(0, $r['summary']['output_tax'], 0.01);
    }
}
