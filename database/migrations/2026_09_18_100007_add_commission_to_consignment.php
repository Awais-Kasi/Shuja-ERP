<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignment_dispatches', function (Blueprint $table) {
            $table->decimal('commission_rate', 8, 6)->default(0)->after('agent_customer_id'); // agent commission on sold value
        });

        Schema::table('consignment_settlements', function (Blueprint $table) {
            $table->decimal('commission_rate', 8, 6)->default(0)->after('tax_amount');
            $table->decimal('commission_amount', 19, 4)->default(0)->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('consignment_dispatches', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });

        Schema::table('consignment_settlements', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_amount']);
        });
    }
};
