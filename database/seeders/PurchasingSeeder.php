<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Purchasing\GoodsReceiptService;
use App\Purchasing\PurchaseBillService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

class PurchasingSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        app(TenantManager::class)->withCompany($company, function () use ($company) {
            $suppliers = [
                ['SUP-STEEL', 'Al-Hadeed Steel Mills', 30],
                ['SUP-PAINT', 'ColorTech Industries', 15],
                ['SUP-GEN', 'General Traders', 0],
            ];
            foreach ($suppliers as [$code, $name, $terms]) {
                Supplier::firstOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    ['name' => $name, 'payment_terms_days' => $terms],
                );
            }

            if (PurchaseOrder::exists()) {
                return;
            }

            $steel = Supplier::where('code', 'SUP-STEEL')->first();
            $item = Item::where('code', 'RM-STEEL')->first();
            $warehouse = Warehouse::where('code', 'RM')->first();
            if (! $steel || ! $item || ! $warehouse) {
                return;
            }

            $qty = 2000;
            $rate = 260;
            $amount = $qty * $rate;
            $today = now()->toDateString();

            // Purchase order
            $po = PurchaseOrder::create([
                'company_id' => $company->id,
                'supplier_id' => $steel->id,
                'warehouse_id' => $warehouse->id,
                'number' => $this->number('purchase_order', 'PO-'),
                'order_date' => $today,
                'status' => 'confirmed',
                'subtotal' => $amount,
            ]);
            $poLine = $po->lines()->create([
                'company_id' => $company->id,
                'item_id' => $item->id,
                'quantity' => $qty,
                'rate' => $rate,
                'amount' => $amount,
            ]);

            // Goods receipt (posts stock + GRNI)
            $grn = GoodsReceipt::create([
                'company_id' => $company->id,
                'supplier_id' => $steel->id,
                'purchase_order_id' => $po->id,
                'warehouse_id' => $warehouse->id,
                'receipt_date' => $today,
                'status' => 'draft',
            ]);
            $grn->lines()->create([
                'company_id' => $company->id,
                'item_id' => $item->id,
                'purchase_order_line_id' => $poLine->id,
                'quantity' => $qty,
                'rate' => $rate,
                'amount' => $amount,
            ]);
            app(GoodsReceiptService::class)->post($grn);

            // Purchase bill (clears GRNI, raises AP + input tax @ 17%)
            $bill = PurchaseBill::create([
                'company_id' => $company->id,
                'supplier_id' => $steel->id,
                'goods_receipt_id' => $grn->id,
                'supplier_invoice_no' => 'AHS-9921',
                'bill_date' => $today,
                'tax_amount' => round($amount * 0.17, 4),
                'status' => 'draft',
            ]);
            $bill->lines()->create([
                'company_id' => $company->id,
                'item_id' => $item->id,
                'quantity' => $qty,
                'rate' => $rate,
                'amount' => $amount,
            ]);
            app(PurchaseBillService::class)->post($bill);
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
