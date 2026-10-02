<?php

namespace Database\Seeders;

use App\Inventory\StockAdjustmentService;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        app(TenantManager::class)->withCompany($company, function () use ($company) {
            $uoms = [];
            foreach ([
                'PCS' => 'Pieces', 'KG' => 'Kilogram', 'LTR' => 'Litre', 'BOX' => 'Box', 'BAG' => 'Bag',
            ] as $code => $name) {
                $uoms[$code] = Uom::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $name]);
            }

            $plant = CostCenter::where('code', 'PLANT')->first();
            $warehouses = [];
            foreach ([
                ['MAIN', 'Main Store', 'warehouse'],
                ['RM', 'Raw Material Store', 'warehouse'],
                ['FG', 'Finished Goods Store', 'warehouse'],
            ] as [$code, $name, $type]) {
                $warehouses[$code] = Warehouse::firstOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    ['name' => $name, 'type' => $type, 'cost_center_id' => $plant?->id],
                );
            }

            $rmAccount = Account::where('code', '1121')->value('id');   // Raw Materials
            $fgAccount = Account::where('code', '1123')->value('id');   // Finished Goods
            $capital = Account::where('code', '3100')->value('id');     // Owner's Capital
            $salesAccount = Account::where('code', '4100')->value('id'); // Sales Revenue
            $cogsAccount = Account::where('code', '5100')->value('id');  // Cost of Goods Sold

            // [code, name, type, uom, inventory_account, valuation_method]
            $itemDefs = [
                ['RM-STEEL', 'Steel Sheet 1.2mm', 'raw_material', 'KG', $rmAccount, 'weighted_average'],
                ['RM-PAINT', 'Industrial Paint', 'raw_material', 'LTR', $rmAccount, 'fifo'],
                ['FG-CAB', 'Steel Cabinet', 'finished_good', 'PCS', $fgAccount, 'weighted_average'],
                ['FG-DESK', 'Office Desk', 'finished_good', 'PCS', $fgAccount, 'fifo'],
                ['CON-BOLT', 'Bolts (Pack of 100)', 'consumable', 'BOX', $rmAccount, null],
            ];

            $items = [];
            foreach ($itemDefs as [$code, $name, $type, $uomCode, $account, $method]) {
                $items[$code] = Item::firstOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    [
                        'name' => $name,
                        'type' => $type,
                        'uom_id' => $uoms[$uomCode]->id,
                        'inventory_account_id' => $account,
                        'income_account_id' => $salesAccount,
                        'cogs_account_id' => $cogsAccount,
                        'valuation_method' => $method,
                    ],
                );
            }

            if (StockAdjustment::where('reason', 'opening')->exists()) {
                return;
            }

            $adjustment = StockAdjustment::create([
                'company_id' => $company->id,
                'adjustment_date' => now()->toDateString(),
                'reason' => 'opening',
                'offset_account_id' => $capital,
                'memo' => 'Opening stock balances',
                'status' => 'draft',
            ]);

            // [item, warehouse, qty, rate]
            $openings = [
                ['RM-STEEL', 'RM', 1000, 250],
                ['RM-PAINT', 'RM', 500, 800],
                ['FG-CAB', 'FG', 50, 12000],
                ['FG-DESK', 'FG', 30, 15000],
                ['CON-BOLT', 'MAIN', 200, 350],
            ];

            foreach ($openings as [$itemCode, $whCode, $qty, $rate]) {
                StockAdjustmentLine::create([
                    'company_id' => $company->id,
                    'stock_adjustment_id' => $adjustment->id,
                    'item_id' => $items[$itemCode]->id,
                    'warehouse_id' => $warehouses[$whCode]->id,
                    'quantity' => $qty,
                    'rate' => $rate,
                ]);
            }

            app(StockAdjustmentService::class)->post($adjustment);
        });
    }
}
