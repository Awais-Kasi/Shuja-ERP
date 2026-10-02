<?php

namespace App\Companies;

use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\NumberSequence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stands up a brand-new company tenant: fiscal calendar, number sequences, a standard
 * chart of accounts + cost centres, an Owner role with every permission, and the
 * creating user attached as owner. Everything a company needs to start transacting.
 */
class CompanyProvisioner
{
    private const SEQUENCES = [
        'journal' => 'JV-', 'sales_order' => 'SO-', 'sales_invoice' => 'INV-', 'sales_return' => 'CRN-',
        'delivery' => 'DN-', 'purchase_order' => 'PO-', 'grn' => 'GRN-', 'purchase_bill' => 'BILL-',
        'purchase_return' => 'DRN-', 'payment' => 'PAY-', 'receipt' => 'RCV-', 'work_order' => 'WO-',
        'consignment' => 'CN-', 'payroll_run' => 'PR-', 'payroll_payment' => 'PP-',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function provision(array $data, User $creator): Company
    {
        return DB::transaction(function () use ($data, $creator) {
            $company = Company::create([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'code' => strtoupper($data['code']),
                'base_currency' => strtoupper($data['base_currency']),
                'country' => $data['country'] ?? null,
                'timezone' => $data['timezone'] ?? 'Asia/Karachi',
                'fiscal_start_month' => (int) ($data['fiscal_start_month'] ?? 1),
                'is_active' => true,
            ]);

            Model::withoutEvents(function () use ($company, $creator) {
                $this->seedFiscalCalendar($company);
                $this->seedSequences($company);
                $this->seedChartOfAccounts($company);
                $this->seedCostCenters($company);
                $owner = $this->seedOwnerRole($company);

                $creator->companies()->syncWithoutDetaching([
                    $company->id => ['role_id' => $owner->id, 'is_default' => $creator->default_company_id === null],
                ]);
                if ($creator->default_company_id === null) {
                    $creator->forceFill(['default_company_id' => $company->id])->save();
                }
            });

            return $company->refresh();
        });
    }

    private function seedFiscalCalendar(Company $company): void
    {
        $now = CarbonImmutable::create(2026, 1, 1); // deterministic base; periods span the fiscal year
        $startMonth = $company->fiscal_start_month;
        $year = $now->month >= $startMonth ? $now->year : $now->year - 1;
        $start = CarbonImmutable::create($year, $startMonth, 1);
        $end = $start->addYear()->subDay();

        $fy = FiscalYear::create(['company_id' => $company->id, 'name' => "FY {$start->year}-{$end->year}", 'starts_on' => $start, 'ends_on' => $end, 'status' => 'open']);
        for ($i = 0; $i < 12; $i++) {
            $ps = $start->addMonths($i);
            $fy->periods()->create(['company_id' => $company->id, 'name' => $ps->format('M Y'), 'starts_on' => $ps, 'ends_on' => $ps->endOfMonth(), 'status' => 'open']);
        }
    }

    private function seedSequences(Company $company): void
    {
        foreach (self::SEQUENCES as $key => $prefix) {
            NumberSequence::create(['company_id' => $company->id, 'key' => $key, 'prefix' => $prefix, 'padding' => 5, 'next_number' => 1]);
        }
    }

    private function seedChartOfAccounts(Company $company): void
    {
        $ids = [];
        foreach (ChartOfAccountsSeeder::ACCOUNTS as [$code, $name, $type, $isGroup, $control, $parentCode]) {
            $account = Account::create([
                'company_id' => $company->id, 'code' => $code, 'name' => $name, 'type' => $type,
                'is_group' => $isGroup, 'control_type' => $control,
                'parent_id' => $parentCode ? ($ids[$parentCode] ?? null) : null, 'is_active' => true,
            ]);
            $ids[$code] = $account->id;
        }
    }

    private function seedCostCenters(Company $company): void
    {
        foreach (ChartOfAccountsSeeder::COST_CENTERS as [$code, $name, $dimension]) {
            CostCenter::create(['company_id' => $company->id, 'code' => $code, 'name' => $name, 'dimension' => $dimension, 'is_active' => true]);
        }
    }

    private function seedOwnerRole(Company $company): Role
    {
        $owner = Role::create(['company_id' => $company->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
        $owner->permissions()->sync(Permission::pluck('id')->all());

        return $owner;
    }
}
