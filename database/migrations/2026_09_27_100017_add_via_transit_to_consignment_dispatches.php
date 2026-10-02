<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignment_dispatches', function (Blueprint $table) {
            // When true the dispatch ships through Goods-in-Transit (1140): posting issues
            // stock into transit and a separate receipt lands it on consignment (1124).
            $table->boolean('via_transit')->default(false)->after('commission_rate');
            $table->timestamp('received_at')->nullable()->after('posted_at');
        });
    }

    public function down(): void
    {
        Schema::table('consignment_dispatches', function (Blueprint $table) {
            $table->dropColumn(['via_transit', 'received_at']);
        });
    }
};
