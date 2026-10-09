<?php

namespace Tests\Feature;

use App\Consignment\ConsignmentTripService;
use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Journal;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsignmentTripTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $wh;

    private Supplier $supplier;

    private Customer $customer;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Trip Co', 'code' => 'T', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $this->acc['inv'] = Account::create(['company_id' => $this->company->id, 'code' => '1123', 'name' => 'Finished Goods', 'type' => 'asset', 'control_type' => 'inventory']);
        $this->acc['cash'] = Account::create(['company_id' => $this->company->id, 'code' => '1101', 'name' => 'Cash', 'type' => 'asset', 'control_type' => 'cash']);
        $this->acc['ar'] = Account::create(['company_id' => $this->company->id, 'code' => '1110', 'name' => 'AR', 'type' => 'asset', 'control_type' => 'ar']);
        $this->acc['ap'] = Account::create(['company_id' => $this->company->id, 'code' => '2110', 'name' => 'AP', 'type' => 'liability', 'control_type' => 'ap']);
        $this->acc['rev'] = Account::create(['company_id' => $this->company->id, 'code' => '4100', 'name' => 'Sales', 'type' => 'income']);
        $this->acc['cogs'] = Account::create(['company_id' => $this->company->id, 'code' => '5100', 'name' => 'COGS', 'type' => 'expense']);

        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'KG', 'name' => 'Kilogram']);
        $this->wh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'KHI', 'name' => 'Karachi', 'type' => 'warehouse']);
        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'BAG', 'name' => 'Plastic Bags', 'uom_id' => $uom->id, 'inventory_account_id' => $this->acc['inv']->id, 'income_account_id' => $this->acc['rev']->id, 'cogs_account_id' => $this->acc['cogs']->id, 'valuation_method' => 'weighted_average']);
        $this->supplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'SUP', 'name' => 'PE Supplier']);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'code' => 'CUS', 'name' => 'Buyer']);
    }

    /** The user's exact Turbat → Gwadar → Karachi demo, end to end. */
    private function demoSteps(): array
    {
        return [
            ['type' => 'loading', 'label' => 'Loading at Turbat', 'location' => 'Turbat', 'basis' => 'per_bag', 'rate' => 10],
            ['type' => 'freight', 'label' => 'Turbat → Gwadar freight', 'basis' => 'flat', 'rate' => 90000],
            ['type' => 'customs', 'label' => 'Customs', 'basis' => 'flat', 'rate' => 150000],
            ['type' => 'unloading', 'label' => 'Unloading at Gwadar', 'location' => 'Gwadar', 'basis' => 'per_bag', 'rate' => 10],
            ['type' => 'processing', 'label' => 'Wrapping/processing', 'basis' => 'per_kg', 'rate' => 3.5],
            ['type' => 'loading', 'label' => 'Loading at Gwadar', 'location' => 'Gwadar', 'basis' => 'per_bag', 'rate' => 10],
            ['type' => 'freight', 'label' => 'Gwadar → Karachi freight', 'basis' => 'flat', 'rate' => 170000],
            ['type' => 'handling', 'label' => 'Driver allowance', 'basis' => 'flat', 'rate' => 10000],
            ['type' => 'unloading', 'label' => 'Unloading at Karachi', 'location' => 'Karachi', 'basis' => 'per_bag', 'rate' => 10],
            ['type' => 'storage', 'label' => 'Storage at Karachi', 'location' => 'Karachi', 'basis' => 'per_bag', 'rate' => 20],
        ];
    }

    public function test_purchase_trip_capitalises_every_leg_and_computes_profit(): void
    {
        $service = app(ConsignmentTripService::class);

        $trip = $service->create([
            'source' => 'purchase', 'vehicle_no' => 'TKL-1234', 'item_id' => $this->item->id,
            'warehouse_id' => $this->wh->id, 'supplier_id' => $this->supplier->id,
            'origin' => 'Turbat', 'destination' => 'Karachi',
            'quantity' => 20000, 'packages' => 1000, 'goods_rate' => 450,
            'trip_date' => Carbon::now()->toDateString(), 'steps' => $this->demoSteps(),
        ]);

        // Amounts derived from basis: goods 20000*450, logistics = 550,000.
        $this->assertEqualsWithDelta(9_000_000, (float) $trip->goods_cost, 0.01);
        $this->assertEqualsWithDelta(550_000, (float) $trip->logistics_cost, 0.01);
        $this->assertEqualsWithDelta(9_550_000, (float) $trip->total_cost, 0.01);

        $service->post($trip->fresh('steps'));
        $trip->refresh();

        // Every cost is now capitalised onto the goods: stock value == total landed cost.
        $balance = StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->wh->id)->first();
        $this->assertEqualsWithDelta(20000, (float) $balance->quantity, 0.0001);
        $this->assertEqualsWithDelta(9_550_000, (float) $balance->value, 0.01);
        $this->assertSame('posted', $trip->status);

        // The posting journal is balanced (PostingEngine enforces it) and exists.
        $this->assertNotNull($trip->journal_id);
        $journal = Journal::find($trip->journal_id);
        $this->assertEqualsWithDelta((float) $journal->lines->sum('debit'), (float) $journal->lines->sum('credit'), 0.01);

        // Sell the batch for 11,000,000 → profit 1,450,000; stock relieved to zero.
        $service->settle($trip, ['sale_amount' => 11_000_000, 'customer_id' => $this->customer->id, 'settlement_date' => Carbon::now()->toDateString()]);
        $trip->refresh();

        $this->assertSame('settled', $trip->status);
        $this->assertEqualsWithDelta(9_550_000, (float) $trip->cogs_total, 0.01);
        $this->assertEqualsWithDelta(1_450_000, (float) $trip->profit, 0.01);

        $balance->refresh();
        $this->assertEqualsWithDelta(0, (float) $balance->quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $balance->value, 0.01);
    }

    public function test_manufactured_trip_adds_logistics_to_existing_stock(): void
    {
        // Goods already produced into stock by a work order: 20000 KG @ 400 production cost.
        app(InventoryService::class)->receive($this->item, $this->wh, 20000, 400);

        $service = app(ConsignmentTripService::class);
        $trip = $service->create([
            'source' => 'manufacture', 'item_id' => $this->item->id, 'warehouse_id' => $this->wh->id,
            'quantity' => 20000, 'packages' => 1000, 'goods_rate' => 400,
            'trip_date' => Carbon::now()->toDateString(), 'steps' => $this->demoSteps(),
        ]);

        $service->post($trip->fresh('steps'));

        // No second receipt: production cost (8,000,000) + logistics (550,000) = 8,550,000.
        $balance = StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->wh->id)->first();
        $this->assertEqualsWithDelta(20000, (float) $balance->quantity, 0.0001);
        $this->assertEqualsWithDelta(8_550_000, (float) $balance->value, 0.01);

        $service->settle($trip->fresh(), ['sale_amount' => 10_000_000, 'settlement_date' => Carbon::now()->toDateString()]);
        $trip->refresh();

        $this->assertEqualsWithDelta(8_550_000, (float) $trip->cogs_total, 0.01);
        $this->assertEqualsWithDelta(1_450_000, (float) $trip->profit, 0.01);
    }

    public function test_step_amount_respects_basis(): void
    {
        $service = app(ConsignmentTripService::class);
        $trip = $service->create([
            'source' => 'purchase', 'item_id' => $this->item->id, 'warehouse_id' => $this->wh->id,
            'quantity' => 500, 'packages' => 40, 'goods_rate' => 100,
            'trip_date' => Carbon::now()->toDateString(),
            'steps' => [
                ['type' => 'loading', 'basis' => 'per_bag', 'rate' => 5],   // 40 * 5 = 200
                ['type' => 'processing', 'basis' => 'per_kg', 'rate' => 2],  // 500 * 2 = 1000
                ['type' => 'freight', 'basis' => 'flat', 'rate' => 3000],    // 3000
            ],
        ]);

        $steps = $trip->steps;
        $this->assertEqualsWithDelta(200, (float) $steps[0]->amount, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $steps[1]->amount, 0.01);
        $this->assertEqualsWithDelta(3000, (float) $steps[2]->amount, 0.01);
        $this->assertEqualsWithDelta(4200, (float) $trip->logistics_cost, 0.01);
    }
}
