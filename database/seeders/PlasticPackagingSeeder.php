<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Bom;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

/**
 * Starter master data for a polyethylene → plastic-bag manufacturer that sells by
 * weight (KG): units, raw materials and finished products mapped to the right
 * accounts, two warehouses, and a bill of material. Idempotent — safe to re-run.
 *
 * Run on the server with:
 *   php8.4 artisan db:seed --class=PlasticPackagingSeeder --force
 */
class PlasticPackagingSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first() ?? Company::first();
        if (! $company) {
            $this->command?->warn('No company found — nothing to seed.');

            return;
        }
        app(TenantManager::class)->set($company);

        $acc = fn (string $code) => optional(Account::where('code', $code)->first())->id;
        $rawInv = $acc('1121');   // Raw Materials
        $fgInv = $acc('1123');    // Finished Goods
        $revenue = $acc('4100');  // Sales Revenue
        $cogs = $acc('5100');     // Cost of Goods Sold

        // Units — KG is the trading/stocking unit for this business.
        $kg = null;
        foreach ([['KG', 'Kilogram'], ['PCS', 'Pieces'], ['ROLL', 'Roll'], ['BAG', 'Bag'], ['CTN', 'Carton']] as [$code, $name]) {
            $u = Uom::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $name, 'is_active' => true]);
            if ($code === 'KG') {
                $kg = $u->id;
            }
        }

        // Warehouses.
        Warehouse::firstOrCreate(['company_id' => $company->id, 'code' => 'RM'], ['name' => 'Raw Material Store', 'type' => 'warehouse', 'is_active' => true]);
        Warehouse::firstOrCreate(['company_id' => $company->id, 'code' => 'FG'], ['name' => 'Finished Goods Store', 'type' => 'warehouse', 'is_active' => true]);

        // Raw materials — bought by weight, weighted-average cost, stocked at 1121.
        foreach ([['RM-LDPE', 'LDPE Granules'], ['RM-HDPE', 'HDPE Granules'], ['RM-MB', 'Colour Masterbatch']] as [$code, $name]) {
            Item::firstOrCreate(['company_id' => $company->id, 'code' => $code], [
                'uom_id' => $kg, 'name' => $name, 'type' => 'raw_material', 'valuation_method' => 'weighted_average',
                'inventory_account_id' => $rawInv, 'tracks_inventory' => true,
                'is_purchasable' => true, 'is_sellable' => false, 'is_active' => true,
            ]);
        }

        // Finished goods — made in-house, sold by weight, stocked at 1123.
        foreach ([['FG-SHOP', 'Shopping Bags'], ['FG-GARB', 'Garbage Bags'], ['FG-ROLL', 'Polyethylene Roll / Sheet']] as [$code, $name]) {
            Item::firstOrCreate(['company_id' => $company->id, 'code' => $code], [
                'uom_id' => $kg, 'name' => $name, 'type' => 'finished_good', 'valuation_method' => 'weighted_average',
                'inventory_account_id' => $fgInv, 'income_account_id' => $revenue, 'cogs_account_id' => $cogs,
                'tracks_inventory' => true, 'is_purchasable' => false, 'is_sellable' => true, 'is_active' => true,
            ]);
        }

        // Bill of material: 100 KG of shopping bags = 98 KG LDPE + 2 KG masterbatch.
        $shop = Item::where('code', 'FG-SHOP')->first();
        $ldpe = Item::where('code', 'RM-LDPE')->first();
        $mb = Item::where('code', 'RM-MB')->first();
        if ($shop && $ldpe && $mb) {
            $bom = Bom::firstOrCreate(['company_id' => $company->id, 'code' => 'BOM-SHOP'], [
                'item_id' => $shop->id, 'name' => 'Shopping Bags — 100 KG batch', 'output_qty' => 100, 'is_active' => true,
            ]);
            if ($bom->lines()->count() === 0) {
                $bom->lines()->create(['company_id' => $company->id, 'component_item_id' => $ldpe->id, 'quantity' => 98]);
                $bom->lines()->create(['company_id' => $company->id, 'component_item_id' => $mb->id, 'quantity' => 2]);
            }
        }

        // One sample partner each to get going — rename/replace with your real ones.
        Supplier::firstOrCreate(['company_id' => $company->id, 'code' => 'SUP-PE'], ['name' => 'Polyethylene Supplier (sample)', 'payable_account_id' => $acc('2110'), 'is_active' => true]);
        Customer::firstOrCreate(['company_id' => $company->id, 'code' => 'CUST-1'], ['name' => 'Sample Customer', 'receivable_account_id' => $acc('1110'), 'is_active' => true]);

        $this->command?->info("Seeded plastic-packaging master data for {$company->name}.");
    }
}
