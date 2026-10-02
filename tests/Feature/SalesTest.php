<?php

namespace Tests\Feature;

use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\StockBalance;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Sales\SalesInvoiceService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    private Customer $customer;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Sales Co', 'code' => 'S', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $this->acc['inv'] = Account::create(['company_id' => $this->company->id, 'code' => '1123', 'name' => 'Inventory', 'type' => 'asset']);
        $this->acc['ar'] = Account::create(['company_id' => $this->company->id, 'code' => '1110', 'name' => 'AR', 'type' => 'asset', 'control_type' => 'ar']);
        $this->acc['tax'] = Account::create(['company_id' => $this->company->id, 'code' => '2120', 'name' => 'Output Tax', 'type' => 'liability', 'control_type' => 'tax']);
        $this->acc['rev'] = Account::create(['company_id' => $this->company->id, 'code' => '4100', 'name' => 'Sales', 'type' => 'income']);
        $this->acc['cogs'] = Account::create(['company_id' => $this->company->id, 'code' => '5100', 'name' => 'COGS', 'type' => 'expense']);

        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->warehouse = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG']);
        $this->item = Item::create([
            'company_id' => $this->company->id, 'code' => 'CAB', 'name' => 'Cabinet', 'uom_id' => $uom->id,
            'inventory_account_id' => $this->acc['inv']->id, 'income_account_id' => $this->acc['rev']->id, 'cogs_account_id' => $this->acc['cogs']->id,
            'valuation_method' => 'weighted_average',
        ]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C', 'name' => 'Customer']);

        // Seed stock: 50 @ 12000
        app(InventoryService::class)->receive($this->item, $this->warehouse, 50, 12000);
    }

    private function makeInvoice(float $qty, float $rate, float $tax): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => Carbon::now()->toDateString(),
            'tax_amount' => $tax,
            'status' => 'draft',
        ]);
        $invoice->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => $qty, 'rate' => $rate, 'amount' => $qty * $rate]);

        return $invoice;
    }

    public function test_invoice_books_revenue_cogs_ar_and_relieves_stock(): void
    {
        $invoice = $this->makeInvoice(10, 18000, 30600);
        app(SalesInvoiceService::class)->post($invoice);
        $invoice->refresh();

        $this->assertSame('posted', $invoice->status);
        $this->assertEquals('120000.0000', $invoice->cogs_total);
        $this->assertEquals('210600.0000', $invoice->total);

        // Stock relieved at WAC cost
        $this->assertEquals('40.0000', StockBalance::where('item_id', $this->item->id)->first()->quantity);

        $j = $invoice->journal;
        $ar = $j->lines->firstWhere('account_id', $this->acc['ar']->id);
        $this->assertEquals('210600.0000', $ar->base_debit);
        $this->assertSame($this->customer->getMorphClass(), $ar->party_type);
        $this->assertEquals('180000.0000', $j->lines->firstWhere('account_id', $this->acc['rev']->id)->base_credit);
        $this->assertEquals('30600.0000', $j->lines->firstWhere('account_id', $this->acc['tax']->id)->base_credit);
        $this->assertEquals('120000.0000', $j->lines->firstWhere('account_id', $this->acc['cogs']->id)->base_debit);
        $this->assertEquals('120000.0000', $j->lines->firstWhere('account_id', $this->acc['inv']->id)->base_credit);
    }

    public function test_invoice_updates_sales_order_progress(): void
    {
        $so = SalesOrder::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => Carbon::now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 180000]);
        $soLine = $so->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => 10, 'rate' => 18000, 'amount' => 180000]);

        $invoice = SalesInvoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'sales_order_id' => $so->id, 'warehouse_id' => $this->warehouse->id, 'invoice_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $invoice->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'sales_order_line_id' => $soLine->id, 'quantity' => 10, 'rate' => 18000, 'amount' => 180000]);

        app(SalesInvoiceService::class)->post($invoice);

        $this->assertEquals('10.0000', $soLine->fresh()->delivered_qty);
        $this->assertSame('delivered', $so->fresh()->status);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }
}
