<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Purchasing\GoodsReceiptService;
use App\Purchasing\PurchaseBillService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchasingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Purch Co', 'code' => 'P', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $this->acc['inv'] = Account::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'Inventory', 'type' => 'asset']);
        $this->acc['grni'] = Account::create(['company_id' => $this->company->id, 'code' => '2115', 'name' => 'GRNI', 'type' => 'liability', 'control_type' => 'grni']);
        $this->acc['ap'] = Account::create(['company_id' => $this->company->id, 'code' => '2110', 'name' => 'AP', 'type' => 'liability', 'control_type' => 'ap']);
        $this->acc['tax'] = Account::create(['company_id' => $this->company->id, 'code' => '1130', 'name' => 'Input Tax', 'type' => 'asset', 'control_type' => 'tax']);

        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'KG', 'name' => 'Kg']);
        $this->warehouse = Warehouse::create(['company_id' => $this->company->id, 'code' => 'RM', 'name' => 'RM Store']);
        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'STEEL', 'name' => 'Steel', 'uom_id' => $uom->id, 'inventory_account_id' => $this->acc['inv']->id]);
        $this->supplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'SUP', 'name' => 'Supplier']);
    }

    private function makeGrn(float $qty, float $rate): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => Carbon::now()->toDateString(),
            'status' => 'draft',
        ]);
        $grn->lines()->create([
            'company_id' => $this->company->id,
            'item_id' => $this->item->id,
            'quantity' => $qty,
            'rate' => $rate,
            'amount' => $qty * $rate,
        ]);

        return $grn;
    }

    public function test_goods_receipt_posts_stock_and_grni(): void
    {
        $grn = $this->makeGrn(100, 50);
        app(GoodsReceiptService::class)->post($grn);

        $grn->refresh();
        $this->assertSame('posted', $grn->status);
        $this->assertEquals('5000.0000', $grn->total_value);

        $this->assertEquals('100.0000', StockBalance::where('item_id', $this->item->id)->first()->quantity);

        $journal = $grn->journal;
        $this->assertEquals('5000.0000', $journal->lines->firstWhere('account_id', $this->acc['inv']->id)->base_debit);
        $this->assertEquals('5000.0000', $journal->lines->firstWhere('account_id', $this->acc['grni']->id)->base_credit);
    }

    public function test_bill_clears_grni_raises_ap_and_recognises_tax(): void
    {
        $grn = $this->makeGrn(100, 50);
        app(GoodsReceiptService::class)->post($grn);

        $bill = PurchaseBill::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'goods_receipt_id' => $grn->id,
            'bill_date' => Carbon::now()->toDateString(),
            'tax_amount' => 850, // 17% of 5000
            'status' => 'draft',
        ]);
        $bill->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => 100, 'rate' => 50, 'amount' => 5000]);

        app(PurchaseBillService::class)->post($bill);
        $bill->refresh();

        $this->assertSame('posted', $bill->status);
        $this->assertEquals('5850.0000', $bill->total);

        $journal = $bill->journal;
        $this->assertEquals('5000.0000', $journal->lines->firstWhere('account_id', $this->acc['grni']->id)->base_debit);
        $this->assertEquals('850.0000', $journal->lines->firstWhere('account_id', $this->acc['tax']->id)->base_debit);

        $apLine = $journal->lines->firstWhere('account_id', $this->acc['ap']->id);
        $this->assertEquals('5850.0000', $apLine->base_credit);
        $this->assertSame($this->supplier->getMorphClass(), $apLine->party_type);
        $this->assertSame($this->supplier->id, (int) $apLine->party_id);

        // GRNI nets to zero across GRN + Bill
        $grniNet = JournalLine::where('account_id', $this->acc['grni']->id)->sum('base_debit')
            - JournalLine::where('account_id', $this->acc['grni']->id)->sum('base_credit');
        $this->assertEqualsWithDelta(0, $grniNet, 0.001);

        // GRN is flagged billed
        $this->assertTrue($grn->fresh()->billed);
    }

    public function test_receipt_updates_purchase_order_progress(): void
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => Carbon::now()->toDateString(),
            'status' => 'confirmed',
            'subtotal' => 5000,
        ]);
        $poLine = $po->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => 100, 'rate' => 50, 'amount' => 5000]);

        $grn = GoodsReceipt::create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => Carbon::now()->toDateString(),
            'status' => 'draft',
        ]);
        $grn->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'purchase_order_line_id' => $poLine->id, 'quantity' => 100, 'rate' => 50, 'amount' => 5000]);

        app(GoodsReceiptService::class)->post($grn);

        $this->assertEquals('100.0000', $poLine->fresh()->received_qty);
        $this->assertSame('received', $po->fresh()->status);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }
}
