<?php

namespace Tests\Feature;

use App\Consignment\ConsignmentDispatchService;
use App\Consignment\ConsignmentException;
use App\Consignment\ConsignmentExpenseService;
use App\Consignment\ConsignmentSettlementService;
use App\Inventory\InventoryException;
use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\ConsignmentDispatch;
use App\Models\ConsignmentExpense;
use App\Models\ConsignmentSettlement;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockFifoLayer;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Item $fifoItem;

    private Warehouse $fg;

    private Warehouse $con;

    private Customer $agent;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Con Co', 'code' => 'C', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $this->acc['fgacc'] = Account::create(['company_id' => $this->company->id, 'code' => '1123', 'name' => 'Finished Goods', 'type' => 'asset', 'control_type' => 'inventory']);
        $this->acc['con'] = Account::create(['company_id' => $this->company->id, 'code' => '1124', 'name' => 'Inventory on Consignment', 'type' => 'asset', 'control_type' => 'inventory']);
        $this->acc['transit'] = Account::create(['company_id' => $this->company->id, 'code' => '1140', 'name' => 'Goods in Transit', 'type' => 'asset']);
        $this->acc['cash'] = Account::create(['company_id' => $this->company->id, 'code' => '1101', 'name' => 'Cash', 'type' => 'asset', 'control_type' => 'cash']);
        $this->acc['ar'] = Account::create(['company_id' => $this->company->id, 'code' => '1110', 'name' => 'AR', 'type' => 'asset', 'control_type' => 'ar']);
        $this->acc['tax'] = Account::create(['company_id' => $this->company->id, 'code' => '2120', 'name' => 'Output Tax', 'type' => 'liability', 'control_type' => 'tax']);
        $this->acc['rev'] = Account::create(['company_id' => $this->company->id, 'code' => '4100', 'name' => 'Sales', 'type' => 'income']);
        $this->acc['cogs'] = Account::create(['company_id' => $this->company->id, 'code' => '5100', 'name' => 'COGS', 'type' => 'expense']);

        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'U', 'name' => 'Unit']);
        $this->fg = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG', 'type' => 'warehouse']);
        $this->con = Warehouse::create(['company_id' => $this->company->id, 'code' => 'CON', 'name' => 'Consignment', 'type' => 'consignment', 'inventory_account_id' => $this->acc['con']->id]);

        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'CAB', 'name' => 'Cabinet', 'uom_id' => $uom->id, 'inventory_account_id' => $this->acc['fgacc']->id, 'income_account_id' => $this->acc['rev']->id, 'cogs_account_id' => $this->acc['cogs']->id, 'valuation_method' => 'weighted_average']);
        $this->fifoItem = Item::create(['company_id' => $this->company->id, 'code' => 'DESK', 'name' => 'Desk', 'uom_id' => $uom->id, 'inventory_account_id' => $this->acc['fgacc']->id, 'income_account_id' => $this->acc['rev']->id, 'cogs_account_id' => $this->acc['cogs']->id, 'valuation_method' => 'fifo']);
        $this->agent = Customer::create(['company_id' => $this->company->id, 'code' => 'AG', 'name' => 'Agent']);

        app(InventoryService::class)->receive($this->item, $this->fg, 100, 1000); // 100 @ 1000
    }

    private function dispatch(Item $item, float $qty): ConsignmentDispatch
    {
        $d = ConsignmentDispatch::create(['company_id' => $this->company->id, 'from_warehouse_id' => $this->fg->id, 'to_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'dispatch_date' => Carbon::now()->toDateString(), 'status' => 'draft']);
        $d->lines()->create(['company_id' => $this->company->id, 'item_id' => $item->id, 'quantity' => $qty]);

        return app(ConsignmentDispatchService::class)->post($d);
    }

    private function conValue(): float
    {
        return (float) StockBalance::where('warehouse_id', $this->con->id)->sum('value');
    }

    private function dispatchViaTransit(Item $item, float $qty): ConsignmentDispatch
    {
        $d = ConsignmentDispatch::create(['company_id' => $this->company->id, 'from_warehouse_id' => $this->fg->id, 'to_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'via_transit' => true, 'dispatch_date' => Carbon::now()->toDateString(), 'status' => 'draft']);
        $d->lines()->create(['company_id' => $this->company->id, 'item_id' => $item->id, 'quantity' => $qty]);

        return app(ConsignmentDispatchService::class)->post($d);
    }

    private function conQty(): float
    {
        return (float) (StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->value('quantity') ?? 0);
    }

    private function gl1124(): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '1124')->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    public function test_via_transit_dispatch_lands_in_goods_in_transit_not_on_consignment(): void
    {
        $d = $this->dispatchViaTransit($this->item, 20); // 20 @ 1,000

        $this->assertSame('in_transit', $d->status);
        $this->assertEqualsWithDelta(0.0, $this->conQty(), 0.0001);   // not on consignment yet
        $this->assertEquals('80.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->fg->id)->first()->quantity);
        $this->assertEqualsWithDelta(20000.0, $this->gl('1140'), 0.01); // Dr Goods in Transit
        $this->assertEqualsWithDelta(0.0, $this->gl1124(), 0.01);       // 1124 untouched
    }

    public function test_receiving_lands_stock_on_consignment_and_clears_transit(): void
    {
        $d = $this->dispatchViaTransit($this->item, 20);
        app(ConsignmentDispatchService::class)->receive($d);
        $d->refresh();

        $this->assertSame('posted', $d->status);
        $this->assertEqualsWithDelta(20.0, $this->conQty(), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->gl('1140'), 0.01);      // transit cleared
        $this->assertEqualsWithDelta(20000.0, $this->gl1124(), 0.01);    // now on consignment
        $this->assertEqualsWithDelta(20000.0, $this->conValue(), 0.01);
    }

    public function test_in_transit_dispatch_cannot_be_settled(): void
    {
        $d = $this->dispatchViaTransit($this->item, 20);
        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 5, 'rate' => 1800, 'returned_qty' => 0]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->post($s);
    }

    public function test_in_transit_dispatch_cannot_be_expensed(): void
    {
        $d = $this->dispatchViaTransit($this->item, 20);
        $expense = ConsignmentExpense::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'warehouse_id' => $this->con->id, 'credit_account_id' => $this->acc['cash']->id, 'expense_date' => Carbon::now()->toDateString(), 'allocation_basis' => 'value', 'status' => 'draft']);
        $expense->lines()->create(['company_id' => $this->company->id, 'expense_type' => 'freight', 'amount' => 1000, 'capitalise' => true]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentExpenseService::class)->post($expense);
    }

    public function test_in_transit_dispatch_reverses_back_to_source(): void
    {
        $d = $this->dispatchViaTransit($this->item, 20);
        app(ConsignmentDispatchService::class)->reverse($d);
        $d->refresh();

        $this->assertSame('reversed', $d->status);
        $this->assertEqualsWithDelta(0.0, $this->gl('1140'), 0.01);   // transit cleared
        $this->assertEquals('100.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->fg->id)->first()->quantity); // all back at source
    }

    public function test_received_via_transit_dispatch_settles_like_a_direct_one(): void
    {
        $d = $this->dispatchViaTransit($this->item, 20);
        app(ConsignmentDispatchService::class)->receive($d);

        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 12, 'rate' => 1800, 'returned_qty' => 0]);
        app(ConsignmentSettlementService::class)->post($s);
        $s->refresh();

        $this->assertSame('posted', $s->status);
        $this->assertEquals('21600.0000', $s->subtotal);           // 12 * 1,800
        $this->assertEqualsWithDelta(12000.0, (float) $s->cogs_total, 0.01); // 12 @ 1,000
    }

    public function test_return_freight_is_expensed_separately_and_reverses(): void
    {
        $freightAcc = Account::create(['company_id' => $this->company->id, 'code' => '5400', 'name' => 'Freight & Landed Costs', 'type' => 'expense']);
        $d = $this->dispatch($this->item, 20); // 20 @ 1,000 on consignment

        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'return_freight' => 500, 'return_freight_account_id' => $this->acc['cash']->id, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 10, 'rate' => 1800, 'returned_qty' => 5]);
        app(ConsignmentSettlementService::class)->post($s);
        $s->refresh();

        $j = $s->journal;
        // Freight: Dr 5400 / Cr cash 500 — a company cost, NOT part of the agent's AR.
        $this->assertEquals('500.0000', $j->lines->firstWhere('account_id', $freightAcc->id)->base_debit);
        $this->assertEquals('500.0000', $j->lines->firstWhere('account_id', $this->acc['cash']->id)->base_credit);
        $this->assertEquals('18000.0000', $j->lines->firstWhere('account_id', $this->acc['ar']->id)->base_debit); // 10 * 1800, freight excluded
        $this->assertEqualsWithDelta(500.0, $this->gl('5400'), 0.01);

        app(ConsignmentSettlementService::class)->reverse($s->refresh());
        $this->assertEqualsWithDelta(0.0, $this->gl('5400'), 0.01); // freight netted back to zero
    }

    public function test_return_freight_needs_a_funding_account(): void
    {
        Account::create(['company_id' => $this->company->id, 'code' => '5400', 'name' => 'Freight', 'type' => 'expense']);
        $d = $this->dispatch($this->item, 20);

        // Freight amount but no funding account → rejected at post time.
        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'return_freight' => 500, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 10, 'rate' => 1800, 'returned_qty' => 0]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->post($s);
    }

    public function test_dispatch_reclassifies_stock_to_consignment(): void
    {
        $d = $this->dispatch($this->item, 20);

        $this->assertSame('posted', $d->status);
        $this->assertEquals('20.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first()->quantity);
        $this->assertEquals('80.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->fg->id)->first()->quantity);

        $j = $d->journal;
        $this->assertEquals('20000.0000', $j->lines->firstWhere('account_id', $this->acc['con']->id)->base_debit);
        $this->assertEquals('20000.0000', $j->lines->firstWhere('account_id', $this->acc['fgacc']->id)->base_credit);
    }

    public function test_landed_cost_capitalises_onto_consignment_stock_wac(): void
    {
        $this->dispatch($this->item, 20); // 20 @ 1000 = 20,000 at CON

        $expense = ConsignmentExpense::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => ConsignmentDispatch::first()->id, 'warehouse_id' => $this->con->id, 'credit_account_id' => $this->acc['cash']->id, 'expense_date' => Carbon::now()->toDateString(), 'allocation_basis' => 'value', 'status' => 'draft']);
        $expense->lines()->create(['company_id' => $this->company->id, 'expense_type' => 'freight', 'amount' => 5000, 'capitalise' => true]);
        app(ConsignmentExpenseService::class)->post($expense);

        $balance = StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first();
        $this->assertEquals('20.0000', $balance->quantity);       // qty unchanged
        $this->assertEquals('25000.0000', $balance->value);       // value +5,000
        $this->assertEquals('1250.0000', $balance->averageRate()); // 25,000 / 20

        $j = $expense->journal;
        $this->assertEquals('5000.0000', $j->lines->firstWhere('account_id', $this->acc['con']->id)->base_debit);
        $this->assertEquals('5000.0000', $j->lines->firstWhere('account_id', $this->acc['cash']->id)->base_credit);
    }

    public function test_landed_cost_bumps_fifo_layers(): void
    {
        app(InventoryService::class)->receive($this->fifoItem, $this->fg, 100, 500);
        $this->dispatch($this->fifoItem, 40); // FIFO: one layer 40 @ 500 at CON

        $expense = ConsignmentExpense::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => ConsignmentDispatch::first()->id, 'warehouse_id' => $this->con->id, 'credit_account_id' => $this->acc['cash']->id, 'expense_date' => Carbon::now()->toDateString(), 'allocation_basis' => 'value', 'status' => 'draft']);
        $expense->lines()->create(['company_id' => $this->company->id, 'expense_type' => 'freight', 'amount' => 4000, 'capitalise' => true]);
        app(ConsignmentExpenseService::class)->post($expense);

        // 40 units, +4,000 → each layer rate rises by 100 → 600
        $layer = StockFifoLayer::where('item_id', $this->fifoItem->id)->where('warehouse_id', $this->con->id)->where('remaining_qty', '>', 0)->first();
        $this->assertEquals('600.0000', $layer->rate);
        $this->assertEquals('24000.0000', StockBalance::where('item_id', $this->fifoItem->id)->where('warehouse_id', $this->con->id)->first()->value);
    }

    public function test_settlement_recognises_sale_and_returns_and_reconciles(): void
    {
        $d = $this->dispatch($this->item, 20);
        $expense = ConsignmentExpense::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'warehouse_id' => $this->con->id, 'credit_account_id' => $this->acc['cash']->id, 'expense_date' => Carbon::now()->toDateString(), 'allocation_basis' => 'value', 'status' => 'draft']);
        $expense->lines()->create(['company_id' => $this->company->id, 'expense_type' => 'freight', 'amount' => 5000, 'capitalise' => true]);
        app(ConsignmentExpenseService::class)->post($expense); // unit cost now 1,250

        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 3672, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 12, 'rate' => 1800, 'returned_qty' => 3]);
        app(ConsignmentSettlementService::class)->post($s);
        $s->refresh();

        $this->assertEquals('21600.0000', $s->subtotal);
        $this->assertEquals('15000.0000', $s->cogs_total);   // 12 @ 1,250
        $this->assertEquals('25272.0000', $s->total);

        $j = $s->journal;
        $this->assertEquals('25272.0000', $j->lines->firstWhere('account_id', $this->acc['ar']->id)->base_debit);
        $this->assertEquals('21600.0000', $j->lines->firstWhere('account_id', $this->acc['rev']->id)->base_credit);
        $this->assertEquals('15000.0000', $j->lines->firstWhere('account_id', $this->acc['cogs']->id)->base_debit);
        // 1124 credited for sold (15,000) + returned (3,750) = 18,750
        $this->assertEquals('18750.0000', $j->lines->firstWhere('account_id', $this->acc['con']->id)->base_credit);

        // 5 units remain on consignment @ 1,250 = 6,250 — and it reconciles to GL 1124
        $this->assertEquals('6250.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first()->value);
        $this->assertEqualsWithDelta($this->conValue(), $this->gl1124(), 0.01);
    }

    public function test_over_settlement_is_blocked_before_posting(): void
    {
        $d = $this->dispatch($this->item, 20);
        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 25, 'rate' => 1800, 'returned_qty' => 0]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->post($s);
    }

    public function test_landed_cost_on_empty_stock_throws(): void
    {
        $this->expectException(InventoryException::class);
        app(InventoryService::class)->addLandedCost($this->item, $this->con, 1000);
    }

    public function test_cumulative_over_settlement_across_duplicate_lines_is_blocked(): void
    {
        $d = $this->dispatch($this->item, 20);
        $dlId = $d->lines->first()->id;

        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        // Two lines each resolving to the SAME dispatch line; individually <= 20 but together 24 > 20.
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $dlId, 'item_id' => $this->item->id, 'sold_qty' => 12, 'rate' => 1800, 'returned_qty' => 0]);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $dlId, 'item_id' => $this->item->id, 'sold_qty' => 12, 'rate' => 1800, 'returned_qty' => 0]);

        try {
            app(ConsignmentSettlementService::class)->post($s);
            $this->fail('Expected over-settlement to be blocked.');
        } catch (ConsignmentException $e) {
            // Nothing posted: no consignment stock relieved (still 20 on hand).
            $this->assertEquals('20.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first()->quantity);
        }
    }

    public function test_settlement_rejects_a_warehouse_that_is_not_the_dispatch_destination(): void
    {
        $d = $this->dispatch($this->item, 20);

        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->fg->id, 'agent_customer_id' => $this->agent->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 1, 'rate' => 1800, 'returned_qty' => 0]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->post($s);
    }

    public function test_untouched_dispatch_can_be_reversed(): void
    {
        $d = $this->dispatch($this->item, 20); // FG 100→80, CON 20 @ 1,000 = 20,000

        $this->assertEquals('20.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first()->quantity);

        app(ConsignmentDispatchService::class)->reverse($d);
        $d->refresh();

        $this->assertSame('reversed', $d->status);
        $this->assertNotNull($d->reversal_journal_id);

        // Stock returned: consignment emptied, source back to its original 100.
        $this->assertEqualsWithDelta(0.0, $this->conValue(), 0.001);
        $this->assertEquals('100.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->fg->id)->first()->quantity);
        $this->assertEquals('100000.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->fg->id)->first()->value);

        // The dispatch and its reversal net GL 1124 to zero.
        $this->assertEqualsWithDelta(0.0, $this->gl1124(), 0.001);
    }

    public function test_settled_dispatch_cannot_be_reversed(): void
    {
        $d = $this->dispatch($this->item, 20);
        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 5, 'rate' => 1800, 'returned_qty' => 0]);
        app(ConsignmentSettlementService::class)->post($s);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentDispatchService::class)->reverse($d->refresh());
    }

    public function test_dispatch_with_capitalised_expense_cannot_be_reversed(): void
    {
        $d = $this->dispatch($this->item, 20);
        $expense = ConsignmentExpense::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'warehouse_id' => $this->con->id, 'credit_account_id' => $this->acc['cash']->id, 'expense_date' => Carbon::now()->toDateString(), 'allocation_basis' => 'value', 'status' => 'draft']);
        $expense->lines()->create(['company_id' => $this->company->id, 'expense_type' => 'freight', 'amount' => 5000, 'capitalise' => true]);
        app(ConsignmentExpenseService::class)->post($expense);

        // Status is still 'posted' but a capitalised expense exists → reversal blocked.
        $this->expectException(ConsignmentException::class);
        app(ConsignmentDispatchService::class)->reverse($d->refresh());
    }

    public function test_a_reversed_dispatch_cannot_be_reversed_again(): void
    {
        $d = $this->dispatch($this->item, 20);
        app(ConsignmentDispatchService::class)->reverse($d);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentDispatchService::class)->reverse($d->refresh());
    }

    public function test_a_reversed_dispatch_cannot_be_settled(): void
    {
        $d = $this->dispatch($this->item, 20);
        app(ConsignmentDispatchService::class)->reverse($d);

        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 5, 'rate' => 1800, 'returned_qty' => 0]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->post($s);
    }

    public function test_a_reversed_dispatch_cannot_take_expenses(): void
    {
        $d = $this->dispatch($this->item, 20);
        app(ConsignmentDispatchService::class)->reverse($d);

        $expense = ConsignmentExpense::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'warehouse_id' => $this->con->id, 'credit_account_id' => $this->acc['cash']->id, 'expense_date' => Carbon::now()->toDateString(), 'allocation_basis' => 'value', 'status' => 'draft']);
        $expense->lines()->create(['company_id' => $this->company->id, 'expense_type' => 'freight', 'amount' => 1000, 'capitalise' => true]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentExpenseService::class)->post($expense);
    }

    public function test_agent_commission_nets_against_ar_and_books_expense(): void
    {
        $d = $this->dispatch($this->item, 20);
        $d->update(['commission_rate' => 0.05]); // 5% agent commission
        $comm = Account::create(['company_id' => $this->company->id, 'code' => '6510', 'name' => 'Sales Commission', 'type' => 'expense']);

        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 10, 'rate' => 1800, 'returned_qty' => 0]);
        app(ConsignmentSettlementService::class)->post($s);
        $s->refresh();

        // subtotal 18,000; commission 900 (5%); AR = total 18,000 − 900 = 17,100 (no tax).
        $this->assertEquals('18000.0000', $s->subtotal);
        $this->assertEquals('900.0000', $s->commission_amount);

        $j = $s->journal;
        $this->assertEquals('17100.0000', $j->lines->firstWhere('account_id', $this->acc['ar']->id)->base_debit);
        $this->assertEquals('900.0000', $j->lines->firstWhere('account_id', $comm->id)->base_debit);
        $this->assertEquals('18000.0000', $j->lines->firstWhere('account_id', $this->acc['rev']->id)->base_credit);
        $this->assertEqualsWithDelta((float) $j->lines->sum('base_debit'), (float) $j->lines->sum('base_credit'), 0.001);
    }

    private function settle(ConsignmentDispatch $d, float $sold, float $returned = 0, float $rate = 1800): ConsignmentSettlement
    {
        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => $sold, 'rate' => $rate, 'returned_qty' => $returned]);

        return app(ConsignmentSettlementService::class)->post($s);
    }

    public function test_settlement_can_be_reversed_restoring_stock_and_gl(): void
    {
        $d = $this->dispatch($this->item, 20); // CON 20 @ 1,000 = 20,000
        $s = $this->settle($d, sold: 12, returned: 3); // sells 12, returns 3 to FG, 5 remain

        $this->assertEquals('5000.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first()->value);

        app(ConsignmentSettlementService::class)->reverse($s);
        $s->refresh();

        $this->assertSame('reversed', $s->status);
        $this->assertNotNull($s->reversal_journal_id);

        // Consignment stock fully restored to the dispatched 20 @ 1,000.
        $this->assertEquals('20.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first()->quantity);
        $this->assertEqualsWithDelta(20000.0, $this->conValue(), 0.01);
        $this->assertEqualsWithDelta($this->conValue(), $this->gl1124(), 0.01); // invariant holds
        $this->assertEqualsWithDelta(20000.0, $this->gl1124(), 0.01);           // 1124 back to the dispatch value

        // AR nets to zero (settlement receivable un-recognised).
        $ar = (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '1110')->sum(DB::raw('l.base_debit - l.base_credit'));
        $this->assertEqualsWithDelta(0.0, $ar, 0.01);

        // Dispatch draw-down restored → back to 'posted'; source warehouse back to 80.
        $d->refresh();
        $this->assertSame('posted', $d->status);
        $this->assertEquals('0.0000', $d->lines()->first()->settled_qty);
        $this->assertEquals('80.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->fg->id)->first()->quantity);
    }

    public function test_only_the_latest_settlement_can_be_reversed(): void
    {
        $d = $this->dispatch($this->item, 20);
        $s1 = $this->settle($d, sold: 5);
        $this->settle($d, sold: 5); // later settlement

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->reverse($s1); // earlier one — a later one exists
    }

    public function test_a_reversed_settlement_cannot_be_reversed_again(): void
    {
        $d = $this->dispatch($this->item, 20);
        $s = $this->settle($d, sold: 5);
        app(ConsignmentSettlementService::class)->reverse($s);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->reverse($s->refresh());
    }

    public function test_settlement_without_captured_cost_cannot_be_reversed(): void
    {
        $d = $this->dispatch($this->item, 20);
        $s = $this->settle($d, sold: 5); // cogs_total 5,000; sold_cost 5,000 captured

        // Simulate a legacy row posted before per-line cost capture existed.
        $s->lines()->update(['sold_cost' => 0, 'returned_cost' => 0]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->reverse($s->refresh());
    }

    public function test_expense_can_be_reversed_removing_capitalised_cost(): void
    {
        $d = $this->dispatch($this->item, 20); // CON 20 @ 1,000 = 20,000
        $expense = ConsignmentExpense::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'warehouse_id' => $this->con->id, 'credit_account_id' => $this->acc['cash']->id, 'expense_date' => Carbon::now()->toDateString(), 'allocation_basis' => 'value', 'status' => 'draft']);
        $expense->lines()->create(['company_id' => $this->company->id, 'expense_type' => 'freight', 'amount' => 5000, 'capitalise' => true]);
        app(ConsignmentExpenseService::class)->post($expense);

        $this->assertEquals('25000.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first()->value);

        app(ConsignmentExpenseService::class)->reverse($expense);
        $expense->refresh();

        $this->assertSame('reversed', $expense->status);
        $this->assertNotNull($expense->reversal_journal_id);
        // Capitalised cost removed → back to the pre-expense value, invariant intact.
        $this->assertEqualsWithDelta(20000.0, $this->conValue(), 0.01);
        $this->assertEqualsWithDelta($this->conValue(), $this->gl1124(), 0.01);
        $this->assertEqualsWithDelta(20000.0, $this->gl1124(), 0.01);
    }

    public function test_expense_cannot_be_reversed_after_a_settlement(): void
    {
        $d = $this->dispatch($this->item, 20);
        $expense = ConsignmentExpense::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'warehouse_id' => $this->con->id, 'credit_account_id' => $this->acc['cash']->id, 'expense_date' => Carbon::now()->toDateString(), 'allocation_basis' => 'value', 'status' => 'draft']);
        $expense->lines()->create(['company_id' => $this->company->id, 'expense_type' => 'freight', 'amount' => 5000, 'capitalise' => true]);
        app(ConsignmentExpenseService::class)->post($expense);

        $this->settle($d, sold: 5); // draws consignment stock down

        $this->expectException(ConsignmentException::class);
        app(ConsignmentExpenseService::class)->reverse($expense->refresh());
    }

    public function test_out_of_range_commission_rate_is_rejected_cleanly(): void
    {
        $d = $this->dispatch($this->item, 20);
        $d->update(['commission_rate' => 1.5]); // 150% — invalid, would otherwise unbalance
        Account::create(['company_id' => $this->company->id, 'code' => '6510', 'name' => 'Sales Commission', 'type' => 'expense']);

        $s = ConsignmentSettlement::create(['company_id' => $this->company->id, 'consignment_dispatch_id' => $d->id, 'consignment_warehouse_id' => $this->con->id, 'agent_customer_id' => $this->agent->id, 'return_warehouse_id' => $this->fg->id, 'settlement_date' => Carbon::now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $s->lines()->create(['company_id' => $this->company->id, 'consignment_dispatch_line_id' => $d->lines->first()->id, 'item_id' => $this->item->id, 'sold_qty' => 10, 'rate' => 1800, 'returned_qty' => 0]);

        $this->expectException(ConsignmentException::class);
        app(ConsignmentSettlementService::class)->post($s);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }
}
