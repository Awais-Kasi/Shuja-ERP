<?php

namespace Database\Seeders;

use App\Consignment\ConsignmentDispatchService;
use App\Consignment\ConsignmentExpenseService;
use App\Consignment\ConsignmentSettlementService;
use App\Models\Account;
use App\Models\Company;
use App\Models\ConsignmentDispatch;
use App\Models\ConsignmentExpense;
use App\Models\ConsignmentSettlement;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;

class ConsignmentSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        app(TenantManager::class)->withCompany($company, function () use ($company) {
            $consignmentAccount = Account::where('code', '1124')->value('id');
            $cash = Account::where('code', '1101')->value('id');

            $con = Warehouse::firstOrCreate(
                ['company_id' => $company->id, 'code' => 'CON-KHI'],
                ['name' => 'Karachi Consignment', 'type' => 'consignment', 'inventory_account_id' => $consignmentAccount],
            );

            $fg = Warehouse::where('code', 'FG')->first();
            $item = Item::where('code', 'FG-CAB')->first();
            $agent = Customer::where('code', 'CUST-METRO')->first();
            if (! $fg || ! $item || ! $agent || ! $consignmentAccount) {
                return;
            }

            if (ConsignmentDispatch::exists()) {
                return;
            }

            // 1) Dispatch 20 cabinets to the consignment location
            $dispatch = ConsignmentDispatch::create([
                'company_id' => $company->id,
                'from_warehouse_id' => $fg->id,
                'to_warehouse_id' => $con->id,
                'agent_customer_id' => $agent->id,
                'commission_rate' => 0.05, // 5% agent commission on sold value
                'dispatch_date' => now()->toDateString(),
                'status' => 'draft',
            ]);
            $dispatch->lines()->create(['company_id' => $company->id, 'item_id' => $item->id, 'quantity' => 20]);
            app(ConsignmentDispatchService::class)->post($dispatch);

            // 2) Capitalise freight of 10,000 onto the consignment stock
            $expense = ConsignmentExpense::create([
                'company_id' => $company->id,
                'consignment_dispatch_id' => $dispatch->id,
                'warehouse_id' => $con->id,
                'credit_account_id' => $cash,
                'expense_date' => now()->toDateString(),
                'allocation_basis' => 'value',
                'status' => 'draft',
            ]);
            $expense->lines()->create(['company_id' => $company->id, 'expense_type' => 'freight', 'amount' => 10000, 'capitalise' => true]);
            app(ConsignmentExpenseService::class)->post($expense);

            // 3) Settle: agent sold 12, returned 3 (5 remain on consignment)
            $settlement = ConsignmentSettlement::create([
                'company_id' => $company->id,
                'consignment_dispatch_id' => $dispatch->id,
                'consignment_warehouse_id' => $con->id,
                'agent_customer_id' => $agent->id,
                'return_warehouse_id' => $fg->id,
                'settlement_date' => now()->toDateString(),
                'tax_amount' => round(12 * 18000 * 0.17, 4),
                'status' => 'draft',
            ]);
            $settlement->lines()->create([
                'company_id' => $company->id,
                'consignment_dispatch_line_id' => $dispatch->lines()->first()->id,
                'item_id' => $item->id,
                'sold_qty' => 12,
                'rate' => 18000,
                'returned_qty' => 3,
            ]);
            app(ConsignmentSettlementService::class)->post($settlement);
        });
    }
}
