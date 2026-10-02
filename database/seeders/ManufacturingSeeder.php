<?php

namespace Database\Seeders;

use App\Manufacturing\ProductionService;
use App\Models\Bom;
use App\Models\Company;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

class ManufacturingSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        app(TenantManager::class)->withCompany($company, function () use ($company) {
            $cabinet = Item::where('code', 'FG-CAB')->first();
            $steel = Item::where('code', 'RM-STEEL')->first();
            $paint = Item::where('code', 'RM-PAINT')->first();
            $rm = Warehouse::where('code', 'RM')->first();
            $fg = Warehouse::where('code', 'FG')->first();
            if (! $cabinet || ! $steel || ! $paint || ! $rm || ! $fg) {
                return;
            }

            $bom = Bom::firstOrCreate(
                ['company_id' => $company->id, 'code' => 'BOM-CAB'],
                ['item_id' => $cabinet->id, 'name' => 'Steel Cabinet — standard', 'output_qty' => 1],
            );
            if ($bom->lines()->count() === 0) {
                $bom->lines()->createMany([
                    ['company_id' => $company->id, 'component_item_id' => $steel->id, 'quantity' => 15],
                    ['company_id' => $company->id, 'component_item_id' => $paint->id, 'quantity' => 2],
                ]);
            }

            if (WorkOrder::exists()) {
                return;
            }

            $produce = 20;
            $wo = WorkOrder::create([
                'company_id' => $company->id,
                'bom_id' => $bom->id,
                'item_id' => $cabinet->id,
                'source_warehouse_id' => $rm->id,
                'target_warehouse_id' => $fg->id,
                'number' => $this->number('work_order', 'WO-'),
                'order_date' => now()->toDateString(),
                'quantity' => $produce,
                'status' => 'draft',
            ]);
            $wo->lines()->createMany([
                ['company_id' => $company->id, 'component_item_id' => $steel->id, 'quantity' => 15 * $produce],
                ['company_id' => $company->id, 'component_item_id' => $paint->id, 'quantity' => 2 * $produce],
            ]);

            $service = app(ProductionService::class);
            $service->issueMaterials($wo);
            $service->complete($wo, overhead: 21000);
        });
    }

    private function number(string $key, string $prefix): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => app(TenantManager::class)->id(), 'key' => $key],
            ['prefix' => $prefix, 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next($key);
    }
}
