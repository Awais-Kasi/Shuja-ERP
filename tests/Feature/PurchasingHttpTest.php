<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchasingHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'PH', 'code' => 'PH', 'base_currency' => 'PKR']);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $inv = Account::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'Inv', 'type' => 'asset']);
        Account::create(['company_id' => $this->company->id, 'code' => '2115', 'name' => 'GRNI', 'type' => 'liability', 'control_type' => 'grni']);
        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'KG', 'name' => 'Kg']);
        $this->warehouse = Warehouse::create(['company_id' => $this->company->id, 'code' => 'RM', 'name' => 'RM']);
        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'ST', 'name' => 'Steel', 'uom_id' => $uom->id, 'inventory_account_id' => $inv->id]);
        $this->supplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'S', 'name' => 'Sup']);
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

    private function payload(): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => Carbon::now()->toDateString(),
            'lines' => [
                ['item_id' => $this->item->id, 'quantity' => 100, 'rate' => 50, 'purchase_order_line_id' => null],
            ],
        ];
    }

    public function test_permitted_user_can_post_a_goods_receipt(): void
    {
        $user = $this->userWith(['purchase.grn.create']);

        $this->actingAs($user)->post('/purchase/receipts', $this->payload())->assertRedirect();

        $this->assertSame(1, GoodsReceipt::count());
        $this->assertEquals('100.0000', StockBalance::where('item_id', $this->item->id)->first()->quantity);
    }

    public function test_user_without_ability_cannot_post_a_goods_receipt(): void
    {
        $user = $this->userWith(['purchase.order.manage']); // not grn.create

        $this->actingAs($user)->post('/purchase/receipts', $this->payload())->assertForbidden();
        $this->assertSame(0, GoodsReceipt::count());
    }
}
