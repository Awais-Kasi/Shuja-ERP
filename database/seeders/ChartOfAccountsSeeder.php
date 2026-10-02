<?php

namespace Database\Seeders;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * [code, name, type, is_group, control_type, parent_code]
     *
     * @var array<int, array{0:string,1:string,2:string,3:bool,4:?string,5:?string}>
     */
    public const ACCOUNTS = [
        ['1000', 'Assets', 'asset', true, null, null],
        ['1100', 'Current Assets', 'asset', true, null, '1000'],
        ['1101', 'Cash in Hand', 'asset', false, 'cash', '1100'],
        ['1102', 'Bank Accounts', 'asset', false, 'bank', '1100'],
        ['1110', 'Accounts Receivable', 'asset', false, 'ar', '1100'],
        ['1120', 'Inventory', 'asset', true, null, '1100'],
        ['1121', 'Raw Materials', 'asset', false, 'inventory', '1120'],
        ['1122', 'Work in Progress', 'asset', false, 'wip', '1120'],
        ['1123', 'Finished Goods', 'asset', false, 'inventory', '1120'],
        ['1124', 'Inventory on Consignment', 'asset', false, 'inventory', '1120'],
        ['1130', 'Input Tax Receivable', 'asset', false, 'tax', '1100'],
        ['1140', 'Goods in Transit', 'asset', false, null, '1100'],
        ['1500', 'Non-Current Assets', 'asset', true, null, '1000'],
        ['1510', 'Property, Plant & Equipment', 'asset', false, null, '1500'],
        ['1520', 'Accumulated Depreciation', 'asset', false, null, '1500'],

        ['2000', 'Liabilities', 'liability', true, null, null],
        ['2100', 'Current Liabilities', 'liability', true, null, '2000'],
        ['2110', 'Accounts Payable', 'liability', false, 'ap', '2100'],
        ['2115', 'Goods Received Not Invoiced', 'liability', false, 'grni', '2100'],
        ['2120', 'Output Tax Payable', 'liability', false, 'tax', '2100'],
        ['2130', 'Accrued Expenses', 'liability', false, null, '2100'],
        ['2140', 'Wages & Salaries Payable', 'liability', false, 'wages_payable', '2100'],
        ['2141', 'Income Tax Payable', 'liability', false, null, '2100'],
        ['2142', 'EOBI Payable', 'liability', false, null, '2100'],
        ['2143', 'Provident Fund Payable', 'liability', false, null, '2100'],
        ['2500', 'Non-Current Liabilities', 'liability', true, null, '2000'],
        ['2510', 'Long-term Loans', 'liability', false, null, '2500'],

        ['3000', 'Equity', 'equity', true, null, null],
        ['3100', "Owner's Capital", 'equity', false, null, '3000'],
        ['3200', 'Retained Earnings', 'equity', false, 'retained_earnings', '3000'],

        ['4000', 'Income', 'income', true, null, null],
        ['4100', 'Sales Revenue', 'income', false, null, '4000'],
        ['4200', 'Other Income', 'income', false, null, '4000'],
        ['4300', 'Interest Income', 'income', false, null, '4000'],
        ['4400', 'Foreign Exchange Gain/(Loss)', 'income', false, null, '4000'],

        ['5000', 'Cost of Sales', 'expense', true, null, null],
        ['5100', 'Cost of Goods Sold', 'expense', false, null, '5000'],
        ['5200', 'Manufacturing Overhead', 'expense', false, null, '5000'],
        ['5400', 'Freight & Landed Costs', 'expense', false, null, '5000'],
        ['5500', 'Inventory Variance', 'expense', false, null, '5000'],

        ['6000', 'Operating Expenses', 'expense', true, null, null],
        ['6100', 'Salaries & Wages', 'expense', false, null, '6000'],
        ['6110', 'Employer EOBI Contribution', 'expense', false, null, '6000'],
        ['6120', 'Employer PF Contribution', 'expense', false, null, '6000'],
        ['6200', 'Rent', 'expense', false, null, '6000'],
        ['6300', 'Utilities', 'expense', false, null, '6000'],
        ['6400', 'Transport & Fuel', 'expense', false, null, '6000'],
        ['6500', 'Loading & Labour', 'expense', false, null, '6000'],
        ['6510', 'Sales Commission', 'expense', false, null, '6000'],
        ['6600', 'Depreciation', 'expense', false, null, '6000'],
        ['6700', 'Bank Charges', 'expense', false, null, '6000'],
        ['6900', 'Miscellaneous Expense', 'expense', false, null, '6000'],
    ];

    public const COST_CENTERS = [
        ['HO', 'Head Office', 'business'],
        ['PLANT', 'Manufacturing Plant', 'business'],
        ['GEN', 'General', 'general'],
    ];

    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        $ids = [];
        foreach (self::ACCOUNTS as [$code, $name, $type, $isGroup, $control, $parentCode]) {
            $account = Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'is_group' => $isGroup,
                    'control_type' => $control,
                    'parent_id' => $parentCode ? ($ids[$parentCode] ?? null) : null,
                    'is_active' => true,
                ],
            );
            $ids[$code] = $account->id;
        }

        foreach (self::COST_CENTERS as [$code, $name, $dimension]) {
            CostCenter::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'dimension' => $dimension, 'is_active' => true],
            );
        }

        $this->seedOpeningBalance($company, $ids);
    }

    /**
     * Post a simple opening capital injection so reports have data.
     *
     * @param  array<string, int>  $ids
     */
    private function seedOpeningBalance(Company $company, array $ids): void
    {
        $tenant = app(TenantManager::class);

        $tenant->withCompany($company, function () use ($ids) {
            // Idempotent: skip if an opening entry already exists.
            if (\App\Models\Journal::where('type', 'opening')->exists()) {
                return;
            }

            app(PostingEngine::class)->post(new LedgerEntry(
                entryDate: now()->toDateString(),
                type: 'opening',
                memo: 'Opening balances — capital injection',
                lines: [
                    LedgerLine::debit($ids['1101'], 500000, 'Cash on hand'),
                    LedgerLine::debit($ids['1102'], 1500000, 'Bank balance'),
                    LedgerLine::credit($ids['3100'], 2000000, "Owner's capital"),
                ],
            ));
        });
    }
}
