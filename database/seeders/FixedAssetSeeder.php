<?php

namespace Database\Seeders;

use App\FixedAssets\FixedAssetService;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

class FixedAssetSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        app(TenantManager::class)->withCompany($company, function () use ($company) {
            if (FixedAsset::exists()) {
                return;
            }

            $ppe = Account::where('code', '1510')->value('id');
            $accum = Account::where('code', '1520')->value('id');
            $dep = Account::where('code', '6600')->value('id');
            $bank = (int) Account::where('code', '1102')->value('id');
            $ho = CostCenter::where('code', 'HO')->value('id');
            $plant = CostCenter::where('code', 'PLANT')->value('id');
            if (! $ppe || ! $accum || ! $dep || ! $bank) {
                return;
            }

            $service = app(FixedAssetService::class);

            $assets = [
                ['FA-001', 'Delivery Van', 'Vehicles', $plant, 800000, 80000, 60, '2026-07-15'],
                ['FA-002', 'Office Computers', 'IT Equipment', $ho, 300000, 0, 36, '2026-07-20'],
                ['FA-003', 'Plant Machinery', 'Machinery', $plant, 1200000, 120000, 120, '2026-07-25'],
            ];

            foreach ($assets as [$code, $name, $category, $cc, $cost, $salvage, $life, $date]) {
                $asset = FixedAsset::create([
                    'company_id' => $company->id,
                    'code' => $code,
                    'name' => $name,
                    'category' => $category,
                    'asset_account_id' => $ppe,
                    'accum_account_id' => $accum,
                    'depreciation_account_id' => $dep,
                    'cost_center_id' => $cc,
                    'cost' => $cost,
                    'salvage_value' => $salvage,
                    'useful_life_months' => $life,
                    'acquisition_date' => $date,
                    'status' => 'active',
                ]);
                $service->acquire($asset, $bank);
            }

            // Post August 2026 depreciation across the assets.
            $service->runDepreciation(2026, 8);
        });
    }
}
