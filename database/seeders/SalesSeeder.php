<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Sales\SalesInvoiceService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

class SalesSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        app(TenantManager::class)->withCompany($company, function () use ($company) {
            foreach ([
                ['CUST-METRO', 'Metro Retailers', 30],
                ['CUST-CITY', 'City Hardware', 15],
                ['CUST-CASH', 'Walk-in Customer', 0],
            ] as [$code, $name, $terms]) {
                Customer::firstOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    ['name' => $name, 'payment_terms_days' => $terms],
                );
            }

            if (SalesOrder::exists()) {
                return;
            }

            $customer = Customer::where('code', 'CUST-METRO')->first();
            $item = Item::where('code', 'FG-CAB')->first();
            $warehouse = Warehouse::where('code', 'FG')->first();
            if (! $customer || ! $item || ! $warehouse) {
                return;
            }

            $qty = 10;
            $rate = 18000;
            $amount = $qty * $rate;
            $today = now()->toDateString();

            $so = SalesOrder::create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'number' => $this->number('sales_order', 'SO-'),
                'order_date' => $today,
                'status' => 'confirmed',
                'subtotal' => $amount,
            ]);
            $soLine = $so->lines()->create([
                'company_id' => $company->id,
                'item_id' => $item->id,
                'quantity' => $qty,
                'rate' => $rate,
                'amount' => $amount,
            ]);

            $invoice = SalesInvoice::create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'sales_order_id' => $so->id,
                'warehouse_id' => $warehouse->id,
                'invoice_date' => $today,
                'tax_amount' => round($amount * 0.17, 4),
                'status' => 'draft',
            ]);
            $invoice->lines()->create([
                'company_id' => $company->id,
                'item_id' => $item->id,
                'sales_order_line_id' => $soLine->id,
                'quantity' => $qty,
                'rate' => $rate,
                'amount' => $amount,
            ]);

            app(SalesInvoiceService::class)->post($invoice);
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
