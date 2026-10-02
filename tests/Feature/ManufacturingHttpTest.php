<?php

namespace Tests\Feature;

use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManufacturingHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private WorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'MH', 'code' => 'MH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $rm = Account::create(['company_id' => $this->company->id, 'code' => '1121', 'name' => 'RM', 'type' => 'asset']);
        Account::create(['company_id' => $this->company->id, 'code' => '1122', 'name' => 'WIP', 'type' => 'asset', 'control_type' => 'wip']);
        $fgAcc = Account::create(['company_id' => $this->company->id, 'code' => '1123', 'name' => 'FG', 'type' => 'asset']);
        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'U', 'name' => 'U']);
        $rmWh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'RM', 'name' => 'RM']);
        $fgWh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG']);
        $steel = Item::create(['company_id' => $this->company->id, 'code' => 'ST', 'name' => 'Steel', 'uom_id' => $uom->id, 'inventory_account_id' => $rm->id]);
        $cab = Item::create(['company_id' => $this->company->id, 'code' => 'CAB', 'name' => 'Cab', 'uom_id' => $uom->id, 'inventory_account_id' => $fgAcc->id]);
        app(InventoryService::class)->receive($steel, $rmWh, 500, 100);

        $this->wo = WorkOrder::create(['company_id' => $this->company->id, 'item_id' => $cab->id, 'source_warehouse_id' => $rmWh->id, 'target_warehouse_id' => $fgWh->id, 'number' => 'WO-1', 'order_date' => Carbon::now()->toDateString(), 'quantity' => 5, 'status' => 'draft']);
        $this->wo->lines()->create(['company_id' => $this->company->id, 'component_item_id' => $steel->id, 'quantity' => 100]);

        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Manufacturing'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_permitted_user_can_issue_materials(): void
    {
        $user = $this->userWith(['manufacturing.workorder.manage']);
        $this->actingAs($user)->post("/manufacturing/work-orders/{$this->wo->id}/issue")->assertRedirect();
        $this->assertSame('in_progress', $this->wo->fresh()->status);
    }

    public function test_user_without_ability_cannot_issue(): void
    {
        $user = $this->userWith(['manufacturing.bom.manage']); // not workorder.manage
        $this->actingAs($user)->post("/manufacturing/work-orders/{$this->wo->id}/issue")->assertForbidden();
        $this->assertSame('draft', $this->wo->fresh()->status);
    }
}
