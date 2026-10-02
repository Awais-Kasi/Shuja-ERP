<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // fifo | weighted_average — default valuation for items that don't override
            $table->string('default_valuation_method')->default('weighted_average')->after('fiscal_start_month');
            $table->boolean('allow_negative_stock')->default(false)->after('default_valuation_method');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['default_valuation_method', 'allow_negative_stock']);
        });
    }
};
