<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignment_settlements', function (Blueprint $table) {
            // Freight the company pays to bring unsold consignment stock back — expensed to
            // Freight & Landed Costs and funded from the chosen cash/bank/payable account.
            $table->decimal('return_freight', 19, 4)->default(0)->after('cogs_total');
            $table->foreignId('return_freight_account_id')->nullable()->after('return_freight')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consignment_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_freight_account_id');
            $table->dropColumn('return_freight');
        });
    }
};
