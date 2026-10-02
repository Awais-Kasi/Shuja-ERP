<?php

namespace Tests\Feature;

use App\Inventory\InventoryService;
use App\Manufacturing\ProductionService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\StockBalance;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManufacturingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $steel;

    private Item $cabinet;

    private Warehouse $rm;

    private Warehouse $fg;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Mfg Co', 'code' => 'M', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $this->acc['rm'] = Account::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'Raw Materials', 'type' => 'asset']);
        $this->acc['wip'] = Account::create(['company_id' => $this->company->id, 'code' => '1122', 'name' => 'WIP', 'type' => 'asset', 'control_type' => 'wip']);
        $this->acc['fg'] = Account::create(['company_id' => $this->company->id, 'code' => '1123', 'name' => 'Finished Goods', 'type' => 'asset']);
        $this->acc['oh'] = Account::create(['company_id' => $this->company->id, 'code' => '5200', 'name' => 'Overhead', 'type' => 'expense']);

        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'U', 'name' => 'Unit']);
        $this->rm = Warehouse::create(['company_id' => $this->company->id, 'code' => 'RM', 'name' => 'RM']);
        $this->fg = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG']);
        $this->steel = Item::create(['company_id' => $this->company->id, 'code' => 'STEEL', 'name' => 'Steel', 'uom_id' => $uom->id, 'inventory_account_id' => $this->acc['rm']->id, 'valuation_method' => 'weighted_average']);
        $this->cabinet = Item::create(['company_id' => $this->company->id, 'code' => 'CAB', 'name' => 'Cabinet', 'uom_id' => $uom->id, 'inventory_account_id' => $this->acc['fg']->id, 'valuation_method' => 'weighted_average']);

        app(InventoryService::class)->receive($this->steel, $this->rm, 1000, 100); // 100,000 of steel
    }

    private function workOrder(float $produce, float $componentQty): WorkOrder
    {
        $wo = WorkOrder::create([
            'company_id' => $this->company->id,
            'item_id' => $this->cabinet->id,
            'source_warehouse_id' => $this->rm->id,
            'target_warehouse_id' => $this->fg->id,
            'number' => 'WO-1',
            'order_date' => Carbon::now()->toDateString(),
            'quantity' => $produce,
            'status' => 'draft',
        ]);
        $wo->lines()->create(['company_id' => $this->company->id, 'component_item_id' => $this->steel->id, 'quantity' => $componentQty]);

        return $wo;
    }

    public function test_issuing_materials_moves_cost_into_wip(): void
    {
        $wo = $this->workOrder(10, 200); // 200 steel @ 100 = 20,000
        app(ProductionService::class)->issueMaterials($wo);
        $wo->refresh();

        $this->assertSame('in_progress', $wo->status);
        $this->assertEquals('20000.0000', $wo->material_cost);
        $this->assertEquals('800.0000', StockBalance::where('item_id', $this->steel->id)->first()->quantity);

        $j = $wo->issue_journal_id ? \App\Models\Journal::find($wo->issue_journal_id) : null;
        $this->assertEquals('20000.0000', $j->lines->firstWhere('account_id', $this->acc['wip']->id)->base_debit);
        $this->assertEquals('20000.0000', $j->lines->firstWhere('account_id', $this->acc['rm']->id)->base_credit);
    }

    public function test_completion_produces_finished_goods_and_nets_wip_to_zero(): void
    {
        $wo = $this->workOrder(10, 200);
        $service = app(ProductionService::class);
        $service->issueMaterials($wo);
        $service->complete($wo->refresh(), overhead: 5000);
        $wo->refresh();

        $this->assertSame('completed', $wo->status);
        $this->assertEquals('25000.0000', $wo->produced_cost); // 20,000 materials + 5,000 overhead

        // Finished goods received: 10 units @ 2,500
        $fgBalance = StockBalance::where('item_id', $this->cabinet->id)->first();
        $this->assertEquals('10.0000', $fgBalance->quantity);
        $this->assertEquals('25000.0000', $fgBalance->value);

        // WIP nets to zero across both journals
        $wipNet = JournalLine::where('account_id', $this->acc['wip']->id)->sum('base_debit')
            - JournalLine::where('account_id', $this->acc['wip']->id)->sum('base_credit');
        $this->assertEqualsWithDelta(0, $wipNet, 0.001);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }
}
