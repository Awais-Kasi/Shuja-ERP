<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\NumberSequence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            PermissionSeeder::class,
        ]);

        $company = Company::firstOrCreate(
            ['code' => 'SHUJA'],
            [
                'name' => 'Shuja Industries',
                'legal_name' => 'Shuja Industries (Pvt) Ltd',
                'base_currency' => 'PKR',
                'country' => 'PK',
                'timezone' => 'Asia/Karachi',
                'fiscal_start_month' => 7,
                'is_active' => true,
            ],
        );

        $this->seedFiscalCalendar($company);
        $roles = $this->seedRoles($company);
        $this->seedNumberSequences($company);
        $this->seedUsers($company, $roles);

        // Structural: the chart of accounts is required for the app to function at all.
        $this->call(ChartOfAccountsSeeder::class);

        // Demo data (sample transactions, stock, employees, FX rates) is for local/staging
        // only. A production install gets the structure above + an admin, nothing more —
        // unless SEED_DEMO=true is set explicitly.
        if ($this->seedsDemoData()) {
            $this->call(InventorySeeder::class);
            $this->call(PurchasingSeeder::class);
            $this->call(SalesSeeder::class);
            $this->call(ManufacturingSeeder::class);
            $this->call(ConsignmentSeeder::class);
            $this->call(HrSeeder::class);
            $this->call(FixedAssetSeeder::class);
            $this->call(ExchangeRateSeeder::class);
        } else {
            $this->command?->warn('Skipped demo data (production). Set SEED_DEMO=true to include it.');
        }
    }

    /** Demo data seeds everywhere except production, where it must be opted into. */
    protected function seedsDemoData(): bool
    {
        return (bool) env('SEED_DEMO', ! app()->environment('production'));
    }

    protected function seedFiscalCalendar(Company $company): void
    {
        $now = CarbonImmutable::now();
        $startMonth = $company->fiscal_start_month;
        $year = $now->month >= $startMonth ? $now->year : $now->year - 1;

        $start = CarbonImmutable::create($year, $startMonth, 1);
        $end = $start->addYear()->subDay();

        $fiscalYear = FiscalYear::firstOrCreate(
            ['company_id' => $company->id, 'name' => "FY {$start->year}-{$end->year}"],
            ['starts_on' => $start, 'ends_on' => $end, 'status' => 'open'],
        );

        for ($i = 0; $i < 12; $i++) {
            $periodStart = $start->addMonths($i);
            $fiscalYear->periods()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $periodStart->format('M Y')],
                [
                    'starts_on' => $periodStart,
                    'ends_on' => $periodStart->endOfMonth(),
                    'status' => 'open',
                ],
            );
        }
    }

    /**
     * @return array<string, Role>
     */
    protected function seedRoles(Company $company): array
    {
        $all = Permission::pluck('name')->all();

        $map = [
            'owner' => ['Owner', $all],
            'accountant' => ['Accountant', $this->pick($all, ['dashboard.view', 'audit.view', 'sales.receipt.manage', 'purchase.payment.manage', 'sales.return.create', 'purchase.return.create'], ['accounting.', 'fixedasset.', 'banking.', 'budget.'])],
            'sales-officer' => ['Sales Officer', $this->pick($all, ['dashboard.view', 'inventory.item.view', 'inventory.stock.view'], ['sales.'])],
            'store-keeper' => ['Store Keeper', $this->pick($all, ['dashboard.view'], ['inventory.', 'purchase.grn'])],
            'hr-manager' => ['HR Manager', $this->pick($all, ['dashboard.view'], ['hr.'])],
            'viewer' => ['Viewer', $this->pick($all, ['dashboard.view'], [], '.view')],
        ];

        $roles = [];
        foreach ($map as $slug => [$name, $abilities]) {
            $role = Role::firstOrCreate(
                ['company_id' => $company->id, 'slug' => $slug],
                ['name' => $name, 'is_system' => true],
            );
            $ids = Permission::whereIn('name', $abilities)->pluck('id')->all();
            $role->permissions()->sync($ids);
            $roles[$slug] = $role;
        }

        return $roles;
    }

    /**
     * Build an ability list from explicit names, group prefixes and/or a suffix.
     *
     * @param  array<int, string>  $all
     * @param  array<int, string>  $explicit
     * @param  array<int, string>  $prefixes
     */
    protected function pick(array $all, array $explicit, array $prefixes, ?string $suffix = null): array
    {
        $result = $explicit;

        foreach ($all as $name) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $result[] = $name;
                }
            }
            if ($suffix && str_ends_with($name, $suffix)) {
                $result[] = $name;
            }
        }

        return array_values(array_unique($result));
    }

    protected function seedNumberSequences(Company $company): void
    {
        $sequences = [
            'journal' => 'JV-',
            'sales_order' => 'SO-',
            'sales_invoice' => 'INV-',
            'sales_return' => 'CRN-',
            'purchase_return' => 'DRN-',
            'delivery' => 'DN-',
            'purchase_order' => 'PO-',
            'grn' => 'GRN-',
            'purchase_bill' => 'BILL-',
            'payment' => 'PAY-',
            'receipt' => 'RCV-',
            'work_order' => 'WO-',
            'consignment' => 'CN-',
            'payroll_run' => 'PR-',
            'payroll_payment' => 'PP-',
        ];

        foreach ($sequences as $key => $prefix) {
            NumberSequence::firstOrCreate(
                ['company_id' => $company->id, 'key' => $key],
                ['prefix' => $prefix, 'padding' => 5, 'next_number' => 1],
            );
        }
    }

    /**
     * @param  array<string, Role>  $roles
     */
    protected function seedUsers(Company $company, array $roles): void
    {
        $isProd = app()->environment('production');
        $adminEmail = env('SEED_ADMIN_EMAIL', 'admin@shuja.test');

        // Never ship a hardcoded password to production: take it from SEED_ADMIN_PASSWORD,
        // else generate a strong random one and print it once so the operator can capture it.
        $envPassword = env('SEED_ADMIN_PASSWORD');
        $adminPassword = $envPassword ?: ($isProd ? Str::password(20) : 'password');

        $admin = User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'System Administrator',
                'password' => $adminPassword,
                'default_company_id' => $company->id,
                'email_verified_at' => now(),
            ],
        );
        $admin->companies()->syncWithoutDetaching([
            $company->id => ['role_id' => $roles['owner']->id, 'is_default' => true],
        ]);

        if ($isProd && ! $envPassword) {
            $this->command?->warn("Generated admin password for {$adminEmail} (store it now, it will not be shown again): {$adminPassword}");
        }

        // The demo accountant (RBAC showcase) is not created in a production install.
        if ($this->seedsDemoData()) {
            $accountant = User::updateOrCreate(
                ['email' => 'accountant@shuja.test'],
                [
                    'name' => 'Ayesha Khan',
                    'password' => env('SEED_ACCOUNTANT_PASSWORD', 'password'),
                    'default_company_id' => $company->id,
                    'email_verified_at' => now(),
                ],
            );
            $accountant->companies()->syncWithoutDetaching([
                $company->id => ['role_id' => $roles['accountant']->id, 'is_default' => true],
            ]);
        }
    }
}
