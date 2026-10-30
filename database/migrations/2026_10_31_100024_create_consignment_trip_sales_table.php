<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per customer a consignment trip's goods are sold to on settlement.
 * Lets a single trip be split across several customers — each with its own
 * quantity, sale amount, relieved cost and profit — while the customer's
 * receivable (and therefore their khata) is stamped to that customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_trip_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consignment_trip_id')->constrained('consignment_trips')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('quantity', 19, 4);
            $table->decimal('amount', 19, 4);   // sale value for this customer
            $table->decimal('cost', 19, 4);     // landed cost relieved for this customer's quantity
            $table->decimal('profit', 19, 4);   // amount - cost
            $table->timestamps();

            $table->index(['company_id', 'consignment_trip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_trip_sales');
    }
};
