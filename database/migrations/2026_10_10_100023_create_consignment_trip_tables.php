<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consignment Trip = a landed-cost journey for a batch of goods.
 *
 * A trip tracks one item travelling under a truck/vehicle number across several
 * legs (loading, freight, customs, unloading, processing, storage). Every leg's
 * cost is capitalised onto the goods (landed costing) so the stock value grows
 * as the batch moves. On settlement the goods are sold and the batch Profit/Loss
 * is Sale - (goods cost + all leg costs). The journey steps are identical whether
 * the goods were purchased or manufactured - only how they first enter stock
 * differs (a purchase books Dr Inventory / Cr Payable; a manufactured batch is
 * already in stock from its work order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number')->nullable();
            $table->string('vehicle_no')->nullable();            // truck number (trip default)
            $table->string('source')->default('purchase');       // purchase|manufacture
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->decimal('quantity', 19, 4);                  // in the item's unit (e.g. KG)
            $table->unsignedInteger('packages')->default(0);     // number of bags/boxes (per-package costs)
            $table->decimal('goods_rate', 19, 4)->default(0);    // purchase/production rate per unit
            $table->decimal('goods_cost', 19, 4)->default(0);    // quantity * goods_rate
            $table->foreignId('goods_credit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('logistics_cost', 19, 4)->default(0); // sum of all leg costs
            $table->decimal('total_cost', 19, 4)->default(0);     // goods_cost + logistics_cost
            $table->foreignId('sale_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('sale_amount', 19, 4)->nullable();
            $table->decimal('cogs_total', 19, 4)->nullable();     // cost relieved on sale (landed WAC)
            $table->decimal('profit', 19, 4)->nullable();         // sale_amount - cogs_total
            $table->string('status')->default('draft');           // draft|posted|settled
            $table->date('trip_date');
            $table->string('memo')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('settlement_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('consignment_trip_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consignment_trip_id')->constrained('consignment_trips')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('type')->default('other');     // loading|freight|customs|unloading|processing|storage|handling|other
            $table->string('label')->nullable();          // human description e.g. "Turbat -> Gwadar freight"
            $table->string('location')->nullable();       // where the leg happens e.g. Turbat
            $table->string('vehicle_no')->nullable();     // truck for this leg (may change mid-journey)
            $table->string('basis')->default('flat');     // per_bag|per_kg|flat
            $table->decimal('rate', 19, 4)->default(0);
            $table->decimal('amount', 19, 4)->default(0); // computed: rate * packages | rate * quantity | rate
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('status')->default('pending'); // pending|done (tracking flag)
            $table->timestamps();

            $table->index(['company_id', 'consignment_trip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_trip_steps');
        Schema::dropIfExists('consignment_trips');
    }
};
