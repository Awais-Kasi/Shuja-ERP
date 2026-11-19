<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Default selling price, so Quick Sale can auto-fill the rate.
            $table->decimal('sale_price', 18, 4)->default(0)->after('standard_cost');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('sale_price');
        });
    }
};
