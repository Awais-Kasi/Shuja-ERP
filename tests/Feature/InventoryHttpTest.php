<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Journal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    private Account $inventory;

    private Account $offset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Inv Http', 'code' => 'IH', 'base_currency' => 'PKR']);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $this->inventory = Account::create(['company_id' => $this->company->id, 'code' => '1120', 'name' => 'Inventory', 'type' => 'asset']);
        $this->offset = Account::create(['company_id' => $this->company->id, 'code' => '3100', 'name' => 'Capital', 'type' => 'equity']);
        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pieces']);
        $this->warehouse = Warehouse::create(['company_id' => $this->company->id, 'code' => 'W1', 'name' => 'WH']);
        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'IT', 'name' => 'Item', 'uom_id' => $uom->id, 'inventory_account_id' => $this->inventory->id]);
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'Role', 'slug' => 'role-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Inventory'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_a_permitted_user_can_post_an_adjustment_and_stock_moves(): void
    {
        $user = $this->userWith(['inventory.adjustment.create']);

        $response = $this->actingAs($user)->post('/inventory/adjustments', [
            'adjustment_date' => Carbon::now()->toDateString(),
            'reason' => 'opening',
            'offset_account_id' => $this->offset->id,
            'lines' => [
                ['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'rate' => 10],
            ],
        ]);

        $balance = StockBalance::where('item_id', $this->item->id)->first();
        $this->assertNotNull($balance);
        $this->assertEquals('100.0000', $balance->quantity);
        $this->assertEquals('1000.0000', $balance->value);

        // and a balanced journal was posted
        $journal = Journal::where('type', 'stock_adjustment')->first();
        $this->assertNotNull($journal);
        $response->assertRedirect();
    }

    public function test_a_user_without_the_ability_cannot_post(): void
    {
        $user = $this->userWith(['inventory.stock.view']); // can view, not adjust

        $this->actingAs($user)->post('/inventory/adjustments', [
            'adjustment_date' => Carbon::now()->toDateString(),
            'reason' => 'opening',
            'offset_account_id' => $this->offset->id,
            'lines' => [
                ['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'rate' => 10],
            ],
        ])->assertForbidden();

        $this->assertSame(0, StockBalance::count());
    }
}
