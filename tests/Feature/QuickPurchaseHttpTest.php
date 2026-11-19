<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PurchaseBill;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuickPurchaseHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'SH', 'code' => 'SH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $inv = Account::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'Inv', 'type' => 'asset']);
        Account::create(['company_id' => $this->company->id, 'code' => '2110', 'name' => 'AP', 'type' => 'liability', 'control_type' => 'ap']);
        Account::create(['company_id' => $this->company->id, 'code' => '2115', 'name' => 'GRNI', 'type' => 'liability', 'control_type' => 'grni']);
        $this->cash = Account::create(['company_id' => $this->company->id, 'code' => '1101', 'name' => 'Cash', 'type' => 'asset', 'control_type' => 'cash']);
        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'KG', 'name' => 'Kg']);
        $this->warehouse = Warehouse::create(['company_id' => $this->company->id, 'code' => 'RM', 'name' => 'RM']);
        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'GRAN', 'name' => 'Granules', 'type' => 'raw_material', 'uom_id' => $uom->id, 'inventory_account_id' => $inv->id, 'tracks_inventory' => true]);
        $this->supplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'S', 'name' => 'Supplier']);

        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Purchase'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_quick_purchase_with_cash_receives_stock_books_bill_and_pays(): void
    {
        $user = $this->userWith(['purchase.bill.post']);

        $this->actingAs($user)->post('/purchase/quick', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => Carbon::now()->toDateString(),
            'lines' => [['item_id' => $this->item->id, 'quantity' => 100, 'rate' => 450]],
            'amount_paid' => 45000,
            'account_id' => $this->cash->id,
        ])->assertRedirect();

        // Stock received.
        $this->assertEquals('100.0000', StockBalance::where('item_id', $this->item->id)->first()->quantity);
        // GRN posted and billed.
        $grn = GoodsReceipt::first();
        $this->assertSame('posted', $grn->status);
        $this->assertTrue((bool) $grn->billed);
        // Bill posted and fully paid.
        $bill = PurchaseBill::first();
        $this->assertSame('posted', $bill->status);
        $this->assertEqualsWithDelta(45000, (float) $bill->total, 0.01);
        $this->assertEqualsWithDelta(45000, (float) $bill->amount_paid, 0.01);
        $this->assertSame(1, Payment::where('direction', 'pay')->count());
    }

    public function test_quick_purchase_on_credit_receives_and_bills_without_payment(): void
    {
        $user = $this->userWith(['purchase.bill.post']);

        $this->actingAs($user)->post('/purchase/quick', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => Carbon::now()->toDateString(),
            'lines' => [['item_id' => $this->item->id, 'quantity' => 10, 'rate' => 500]],
        ])->assertRedirect();

        $bill = PurchaseBill::first();
        $this->assertSame('posted', $bill->status);
        $this->assertEqualsWithDelta(0, (float) $bill->amount_paid, 0.01);
        $this->assertSame(0, Payment::count());
        $this->assertEquals('10.0000', StockBalance::where('item_id', $this->item->id)->first()->quantity);
    }

    public function test_quick_purchase_requires_bill_permission(): void
    {
        $user = $this->userWith(['purchase.supplier.manage']); // not bill.post

        $this->actingAs($user)->post('/purchase/quick', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => Carbon::now()->toDateString(),
            'lines' => [['item_id' => $this->item->id, 'quantity' => 1, 'rate' => 10]],
        ])->assertForbidden();

        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, PurchaseBill::count());
    }
}
