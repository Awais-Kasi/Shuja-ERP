<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'PYH', 'code' => 'PYH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        Account::create(['company_id' => $this->company->id, 'code' => '1102', 'name' => 'Bank', 'type' => 'asset', 'control_type' => 'bank']);
        Account::create(['company_id' => $this->company->id, 'code' => '1110', 'name' => 'AR', 'type' => 'asset', 'control_type' => 'ar']);

        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Payments'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_receipts_require_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/sales/receipts')->assertForbidden();
        $this->actingAs($this->userWith(['sales.receipt.manage']))->get('/sales/receipts')->assertOk();
    }

    public function test_payments_require_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/purchase/payments')->assertForbidden();
        $this->actingAs($this->userWith(['purchase.payment.manage']))->get('/purchase/payments')->assertOk();
    }

    public function test_recording_a_receipt_posts_a_payment(): void
    {
        app(TenantManager::class)->set($this->company);
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C1', 'name' => 'Cust', 'receivable_account_id' => Account::where('code', '1110')->value('id')]);
        $wh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG', 'type' => 'warehouse']);
        $inv = SalesInvoice::create(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'warehouse_id' => $wh->id, 'number' => 'INV-1', 'invoice_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => 5000, 'tax_amount' => 0, 'total' => 5000, 'amount_paid' => 0, 'cogs_total' => 0]);
        app(TenantManager::class)->forget();

        $payload = [
            'party_id' => $customer->id, 'account_id' => Account::where('code', '1102')->value('id'),
            'payment_date' => now()->toDateString(), 'amount' => 5000,
            'allocations' => [['id' => $inv->id, 'amount' => 5000]],
        ];

        $this->actingAs($this->userWith(['sales.receipt.manage']))->post('/sales/receipts', $payload)->assertRedirect();
        $this->assertSame(1, Payment::count());
        $this->assertSame('posted', Payment::first()->status);
    }

    public function test_amount_must_be_positive(): void
    {
        app(TenantManager::class)->set($this->company);
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C2', 'name' => 'Cust', 'receivable_account_id' => Account::where('code', '1110')->value('id')]);
        app(TenantManager::class)->forget();

        $payload = ['party_id' => $customer->id, 'account_id' => Account::where('code', '1102')->value('id'), 'payment_date' => now()->toDateString(), 'amount' => 0];
        $this->actingAs($this->userWith(['sales.receipt.manage']))->post('/sales/receipts', $payload)->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());
    }
}
