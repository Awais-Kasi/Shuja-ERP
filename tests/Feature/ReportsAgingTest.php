<?php

namespace Tests\Feature;

use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Reports\AgingReports;
use App\Reports\InventoryReports;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsAgingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $wh;

    private string $asOf = '2026-09-27';

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Rep Co', 'code' => 'REP', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);
        $this->wh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG', 'type' => 'warehouse']);
        Account::create(['company_id' => $this->company->id, 'code' => '1123', 'name' => 'Inventory', 'type' => 'asset', 'control_type' => 'inventory']);
    }

    private function item(string $code, string $method): Item
    {
        return Item::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $code, 'uom_id' => $this->uom(), 'valuation_method' => $method, 'inventory_account_id' => Account::where('code', '1123')->value('id'), 'tracks_inventory' => true]);
    }

    private function ago(int $days): string
    {
        return Carbon::parse($this->asOf)->subDays($days)->toDateString();
    }

    private function invoice(Customer $c, float $total, float $paid, string $due): void
    {
        static $n = 0;
        $n++;
        SalesInvoice::create(['company_id' => $this->company->id, 'customer_id' => $c->id, 'warehouse_id' => $this->wh->id, 'number' => 'INV-'.$n, 'invoice_date' => $this->ago(120), 'due_date' => $due, 'status' => 'posted', 'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'amount_paid' => $paid, 'cogs_total' => 0]);
    }

    public function test_aged_receivables_buckets_open_invoices(): void
    {
        $c = Customer::create(['company_id' => $this->company->id, 'code' => 'C', 'name' => 'Cust']);
        $this->invoice($c, 1000, 0, $this->asOf);        // current
        $this->invoice($c, 500, 0, $this->ago(15));      // 1-30
        $this->invoice($c, 300, 0, $this->ago(45));      // 31-60
        $this->invoice($c, 200, 50, $this->ago(100));    // 90+ , outstanding 150

        $r = app(AgingReports::class)->receivables($this->asOf);
        $row = $r['rows'][0];

        $this->assertEqualsWithDelta(1000, $row['current'], 0.01);
        $this->assertEqualsWithDelta(500, $row['d1_30'], 0.01);
        $this->assertEqualsWithDelta(300, $row['d31_60'], 0.01);
        $this->assertEqualsWithDelta(150, $row['d90_plus'], 0.01);
        $this->assertEqualsWithDelta(1950, $row['total'], 0.01);
        $this->assertEqualsWithDelta(1950, $r['totals']['total'], 0.01);
    }

    public function test_aged_payables_buckets_open_bills(): void
    {
        $s = Supplier::create(['company_id' => $this->company->id, 'code' => 'S', 'name' => 'Supp']);
        PurchaseBill::create(['company_id' => $this->company->id, 'supplier_id' => $s->id, 'number' => 'B1', 'bill_date' => $this->ago(120), 'due_date' => $this->ago(45), 'status' => 'posted', 'subtotal' => 800, 'tax_amount' => 0, 'total' => 800, 'amount_paid' => 0]);
        PurchaseBill::create(['company_id' => $this->company->id, 'supplier_id' => $s->id, 'number' => 'B2', 'bill_date' => $this->ago(120), 'due_date' => $this->ago(200), 'status' => 'posted', 'subtotal' => 400, 'tax_amount' => 0, 'total' => 400, 'amount_paid' => 400]); // fully paid → excluded

        $r = app(AgingReports::class)->payables($this->asOf);
        $this->assertCount(1, $r['rows']);
        $this->assertEqualsWithDelta(800, $r['rows'][0]['d31_60'], 0.01);
        $this->assertEqualsWithDelta(800, $r['totals']['total'], 0.01);
    }

    public function test_inventory_valuation_totals(): void
    {
        $fifo = $this->item('F', 'fifo');
        $wac = $this->item('W', 'weighted_average');
        $inv = app(InventoryService::class);
        $inv->receive($fifo, $this->wh, 10, 100, ['posting_date' => $this->ago(60), 'entry_type' => 'receipt']);
        $inv->receive($fifo, $this->wh, 5, 120, ['posting_date' => $this->ago(10), 'entry_type' => 'receipt']);
        $inv->receive($wac, $this->wh, 20, 50, ['posting_date' => $this->ago(100), 'entry_type' => 'receipt']);

        $v = app(InventoryReports::class)->valuation();
        $this->assertEqualsWithDelta(2600, $v['total'], 0.01); // 1000 + 600 + 1000
    }

    public function test_stock_aging_buckets_fifo_layers_and_wac_by_last_receipt(): void
    {
        $fifo = $this->item('F', 'fifo');
        $wac = $this->item('W', 'weighted_average');
        $inv = app(InventoryService::class);
        $inv->receive($fifo, $this->wh, 10, 100, ['posting_date' => $this->ago(60), 'entry_type' => 'receipt']); // 1000 → 31-60
        $inv->receive($fifo, $this->wh, 5, 120, ['posting_date' => $this->ago(10), 'entry_type' => 'receipt']);  // 600 → 0-30
        $inv->receive($wac, $this->wh, 20, 50, ['posting_date' => $this->ago(100), 'entry_type' => 'receipt']);  // 1000 → 90+

        $a = app(InventoryReports::class)->aging($this->asOf);

        $this->assertEqualsWithDelta(600, $a['totals']['d1_30'], 0.01);
        $this->assertEqualsWithDelta(1000, $a['totals']['d31_60'], 0.01);
        $this->assertEqualsWithDelta(1000, $a['totals']['d90_plus'], 0.01);
        $this->assertEqualsWithDelta(2600, $a['totals']['total'], 0.01);
        // Aging must reconcile to the valuation total.
        $this->assertEqualsWithDelta(app(InventoryReports::class)->valuation()['total'], $a['totals']['total'], 0.01);
    }

    public function test_stock_aging_reconciles_when_a_fifo_item_has_no_layers(): void
    {
        // A FIFO item can end up with a balance but no live layers (e.g. stock arrived via
        // a consignment receipt/transfer). It must still appear, aged by last receipt.
        $fifo = $this->item('F', 'fifo');
        app(InventoryService::class)->receive($fifo, $this->wh, 10, 100, ['posting_date' => $this->ago(45), 'entry_type' => 'receipt']);
        \App\Models\StockFifoLayer::where('item_id', $fifo->id)->delete(); // simulate missing layers

        $a = app(InventoryReports::class)->aging($this->asOf);
        $v = app(InventoryReports::class)->valuation();

        $this->assertEqualsWithDelta(1000, $v['total'], 0.01);
        $this->assertEqualsWithDelta(1000, $a['totals']['total'], 0.01);       // not lost
        $this->assertEqualsWithDelta(1000, $a['totals']['d31_60'], 0.01);      // aged by last receipt (45d)
    }

    private function uom(): int
    {
        return Uom::create(['company_id' => $this->company->id, 'code' => 'U'.uniqid(), 'name' => 'Unit'])->id;
    }
}
