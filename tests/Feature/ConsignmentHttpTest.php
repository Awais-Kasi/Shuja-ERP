<?php

namespace Tests\Feature;

use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\ConsignmentDispatch;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsignmentHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $fg;

    private Warehouse $con;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'CH', 'code' => 'CH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $fgAcc = Account::create(['company_id' => $this->company->id, 'code' => '1123', 'name' => 'FG', 'type' => 'asset']);
        $conAcc = Account::create(['company_id' => $this->company->id, 'code' => '1124', 'name' => 'Consignment', 'type' => 'asset', 'control_type' => 'inventory']);
        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'U', 'name' => 'U']);
        $this->fg = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG', 'type' => 'warehouse']);
        $this->con = Warehouse::create(['company_id' => $this->company->id, 'code' => 'CON', 'name' => 'Con', 'type' => 'consignment', 'inventory_account_id' => $conAcc->id]);
        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'CAB', 'name' => 'Cab', 'uom_id' => $uom->id, 'inventory_account_id' => $fgAcc->id]);
        Customer::create(['company_id' => $this->company->id, 'code' => 'AG', 'name' => 'Agent']);

        app(InventoryService::class)->receive($this->item, $this->fg, 100, 1000);
        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Consignment'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    private function payload(): array
    {
        return [
            'from_warehouse_id' => $this->fg->id,
            'to_warehouse_id' => $this->con->id,
            'dispatch_date' => Carbon::now()->toDateString(),
            'lines' => [['item_id' => $this->item->id, 'quantity' => 10]],
        ];
    }

    public function test_permitted_user_can_post_a_dispatch(): void
    {
        $user = $this->userWith(['consignment.manage']);
        $this->actingAs($user)->post('/consignment/dispatches', $this->payload())->assertRedirect();

        $this->assertSame(1, ConsignmentDispatch::count());
        $this->assertEquals('10.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->con->id)->first()->quantity);
    }

    public function test_user_without_ability_cannot_dispatch(): void
    {
        $user = $this->userWith(['consignment.settle']); // not consignment.manage
        $this->actingAs($user)->post('/consignment/dispatches', $this->payload())->assertForbidden();
        $this->assertSame(0, ConsignmentDispatch::count());
    }

    public function test_permitted_user_can_reverse_a_dispatch(): void
    {
        $user = $this->userWith(['consignment.manage']);
        $this->actingAs($user)->post('/consignment/dispatches', $this->payload())->assertRedirect();
        $dispatch = ConsignmentDispatch::firstOrFail();

        $this->actingAs($user)->post("/consignment/dispatches/{$dispatch->id}/reverse")->assertRedirect();

        $this->assertSame('reversed', $dispatch->refresh()->status);
        // Goods are back at the source warehouse.
        $this->assertEquals('100.0000', StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->fg->id)->first()->quantity);
    }

    public function test_user_without_manage_cannot_reverse_a_dispatch(): void
    {
        $manager = $this->userWith(['consignment.manage']);
        $this->actingAs($manager)->post('/consignment/dispatches', $this->payload())->assertRedirect();
        $dispatch = ConsignmentDispatch::firstOrFail();

        $other = $this->userWith(['consignment.settle']); // lacks consignment.manage
        $this->actingAs($other)->post("/consignment/dispatches/{$dispatch->id}/reverse")->assertForbidden();
        $this->assertSame('posted', $dispatch->refresh()->status);
    }
}
