<?php

namespace Tests\Feature;

use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\StockBalance;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuickSaleHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    private Customer $customer;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'SH', 'code' => 'SH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $inv = Account::create(['company_id' => $this->company->id, 'code' => '1123', 'name' => 'Inv', 'type' => 'asset']);
        Account::create(['company_id' => $this->company->id, 'code' => '1110', 'name' => 'AR', 'type' => 'asset', 'control_type' => 'ar']);
        $rev = Account::create(['company_id' => $this->company->id, 'code' => '4100', 'name' => 'Sales', 'type' => 'income']);
        $cogs = Account::create(['company_id' => $this->company->id, 'code' => '5100', 'name' => 'COGS', 'type' => 'expense']);
        $this->cash = Account::create(['company_id' => $this->company->id, 'code' => '1101', 'name' => 'Cash in Hand', 'type' => 'asset', 'control_type' => 'cash']);
        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'PCS', 'name' => 'Pcs']);
        $this->warehouse = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG']);
        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'CAB', 'name' => 'Cabinet', 'uom_id' => $uom->id, 'inventory_account_id' => $inv->id, 'income_account_id' => $rev->id, 'cogs_account_id' => $cogs->id]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C', 'name' => 'Customer']);

        app(InventoryService::class)->receive($this->item, $this->warehouse, 50, 12000);
        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Sales'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_quick_sale_with_cash_posts_invoice_and_receipt_and_settles_balance(): void
    {
        $user = $this->userWith(['sales.invoice.post']);

        $this->actingAs($user)->post('/sales/quick', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => Carbon::now()->toDateString(),
            'lines' => [['item_id' => $this->item->id, 'quantity' => 5, 'rate' => 18000]],
            'amount_received' => 90000,
            'account_id' => $this->cash->id,
        ])->assertRedirect();

        $invoice = SalesInvoice::first();
        $this->assertNotNull($invoice);
        $this->assertSame('posted', $invoice->status);
        $this->assertEqualsWithDelta(90000, (float) $invoice->total, 0.01);
        // Receipt fully settled the invoice.
        $this->assertEqualsWithDelta(90000, (float) $invoice->amount_paid, 0.01);
        $this->assertSame(1, Payment::where('direction', 'receive')->count());
        // Stock relieved 5 → 45 remain.
        $this->assertEquals('45.0000', StockBalance::where('item_id', $this->item->id)->first()->quantity);
    }

    public function test_quick_sale_on_credit_posts_invoice_only(): void
    {
        $user = $this->userWith(['sales.invoice.post']);

        $this->actingAs($user)->post('/sales/quick', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => Carbon::now()->toDateString(),
            'lines' => [['item_id' => $this->item->id, 'quantity' => 2, 'rate' => 20000]],
        ])->assertRedirect();

        $invoice = SalesInvoice::first();
        $this->assertSame('posted', $invoice->status);
        $this->assertEqualsWithDelta(0, (float) $invoice->amount_paid, 0.01);
        $this->assertSame(0, Payment::count());
    }

    public function test_quick_sale_requires_invoice_permission(): void
    {
        $user = $this->userWith(['sales.order.manage']); // not invoice.post

        $this->actingAs($user)->post('/sales/quick', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => Carbon::now()->toDateString(),
            'lines' => [['item_id' => $this->item->id, 'quantity' => 1, 'rate' => 100]],
        ])->assertForbidden();

        $this->assertSame(0, SalesInvoice::count());
    }
}
