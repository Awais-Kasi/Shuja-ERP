<?php

namespace Tests\Feature;

use App\Inventory\InventoryException;
use App\Inventory\InventoryService;
use App\Inventory\StockAdjustmentService;
use App\Inventory\StockTransferService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockBalance;
use App\Models\StockFifoLayer;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $wh1;

    private Warehouse $wh2;

    private Item $wac;

    private Item $fifo;

    private Account $inventoryAccount;

    private Account $offset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Inv Co', 'code' => 'I', 'base_currency' => 'PKR', 'default_valuation_method' => 'weighted_average']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $this->inventoryAccount = Account::create(['company_id' => $this->company->id, 'code' => '1120', 'name' => 'Inventory', 'type' => 'asset']);
        $this->offset = Account::create(['company_id' => $this->company->id, 'code' => '3100', 'name' => 'Capital', 'type' => 'equity']);

        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->wh1 = Warehouse::create(['company_id' => $this->company->id, 'code' => 'W1', 'name' => 'WH1']);
        $this->wh2 = Warehouse::create(['company_id' => $this->company->id, 'code' => 'W2', 'name' => 'WH2']);

        $this->wac = Item::create(['company_id' => $this->company->id, 'code' => 'WAC', 'name' => 'WAC Item', 'uom_id' => $uom->id, 'valuation_method' => 'weighted_average', 'inventory_account_id' => $this->inventoryAccount->id]);
        $this->fifo = Item::create(['company_id' => $this->company->id, 'code' => 'FIFO', 'name' => 'FIFO Item', 'uom_id' => $uom->id, 'valuation_method' => 'fifo', 'inventory_account_id' => $this->inventoryAccount->id]);
    }

    private function inv(): InventoryService
    {
        return app(InventoryService::class);
    }

    private function balance(Item $item, Warehouse $wh): StockBalance
    {
        return StockBalance::where('item_id', $item->id)->where('warehouse_id', $wh->id)->first();
    }

    public function test_weighted_average_costs_issues_at_the_moving_average(): void
    {
        $this->inv()->receive($this->wac, $this->wh1, 100, 10);
        $this->inv()->receive($this->wac, $this->wh1, 100, 20);

        $balance = $this->balance($this->wac, $this->wh1);
        $this->assertEquals('200.0000', $balance->quantity);
        $this->assertEquals('3000.0000', $balance->value); // avg 15

        $issue = $this->inv()->issue($this->wac, $this->wh1, 50);
        $this->assertEquals('-750.0000', $issue->value); // 50 * 15

        $balance->refresh();
        $this->assertEquals('150.0000', $balance->quantity);
        $this->assertEquals('2250.0000', $balance->value);
    }

    public function test_fifo_consumes_oldest_layers_first(): void
    {
        $this->inv()->receive($this->fifo, $this->wh1, 100, 10);
        $this->inv()->receive($this->fifo, $this->wh1, 100, 20);

        // Issue 150: 100 @10 + 50 @20 = 2000
        $issue = $this->inv()->issue($this->fifo, $this->wh1, 150);
        $this->assertEquals('-2000.0000', $issue->value);

        $balance = $this->balance($this->fifo, $this->wh1);
        $this->assertEquals('50.0000', $balance->quantity);
        $this->assertEquals('1000.0000', $balance->value); // remaining 50 @20

        $remaining = StockFifoLayer::where('item_id', $this->fifo->id)->where('remaining_qty', '>', 0)->get();
        $this->assertCount(1, $remaining);
        $this->assertEquals('50.0000', $remaining->first()->remaining_qty);
        $this->assertEquals('20.0000', $remaining->first()->rate);
    }

    public function test_issuing_more_than_available_is_blocked(): void
    {
        $this->inv()->receive($this->wac, $this->wh1, 10, 5);

        $this->expectException(InventoryException::class);
        $this->inv()->issue($this->wac, $this->wh1, 20);
    }

    public function test_stock_adjustment_posts_balanced_gl_and_moves_stock(): void
    {
        $adjustment = StockAdjustment::create([
            'company_id' => $this->company->id,
            'adjustment_date' => Carbon::now()->toDateString(),
            'reason' => 'opening',
            'offset_account_id' => $this->offset->id,
            'status' => 'draft',
        ]);
        StockAdjustmentLine::create([
            'company_id' => $this->company->id,
            'stock_adjustment_id' => $adjustment->id,
            'item_id' => $this->wac->id,
            'warehouse_id' => $this->wh1->id,
            'quantity' => 100,
            'rate' => 10,
        ]);

        app(StockAdjustmentService::class)->post($adjustment->fresh());

        $adjustment->refresh();
        $this->assertSame('posted', $adjustment->status);
        $this->assertNotNull($adjustment->journal_id);

        $journal = $adjustment->journal;
        $this->assertSame('posted', $journal->status);
        $this->assertEquals('1000.0000', $journal->lines->firstWhere('account_id', $this->inventoryAccount->id)->base_debit);
        $this->assertEquals('1000.0000', $journal->lines->firstWhere('account_id', $this->offset->id)->base_credit);

        $this->assertEquals('100.0000', $this->balance($this->wac, $this->wh1)->quantity);
    }

    public function test_transfer_moves_stock_and_value_between_warehouses(): void
    {
        $this->inv()->receive($this->wac, $this->wh1, 100, 10);

        $transfer = StockTransfer::create([
            'company_id' => $this->company->id,
            'transfer_date' => Carbon::now()->toDateString(),
            'from_warehouse_id' => $this->wh1->id,
            'to_warehouse_id' => $this->wh2->id,
            'status' => 'draft',
        ]);
        StockTransferLine::create([
            'company_id' => $this->company->id,
            'stock_transfer_id' => $transfer->id,
            'item_id' => $this->wac->id,
            'quantity' => 40,
        ]);

        app(StockTransferService::class)->post($transfer->fresh());

        $this->assertEquals('60.0000', $this->balance($this->wac, $this->wh1)->quantity);
        $this->assertEquals('600.0000', $this->balance($this->wac, $this->wh1)->value);
        $this->assertEquals('40.0000', $this->balance($this->wac, $this->wh2)->quantity);
        $this->assertEquals('400.0000', $this->balance($this->wac, $this->wh2)->value);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }
}
