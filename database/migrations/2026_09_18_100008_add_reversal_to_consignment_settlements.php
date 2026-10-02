<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignment_settlements', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('posted_at');
            $table->foreignId('reversal_journal_id')->nullable()->after('journal_id')->constrained('journals')->nullOnDelete();
        });

        // Snapshot the cost relieved per leg so a reversal can restore consignment stock
        // to exactly the value that was removed.
        Schema::table('consignment_settlement_lines', function (Blueprint $table) {
            $table->decimal('sold_cost', 19, 4)->default(0)->after('amount');
            $table->decimal('returned_cost', 19, 4)->default(0)->after('sold_cost');
        });
    }

    public function down(): void
    {
        Schema::table('consignment_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_id');
            $table->dropColumn('reversed_at');
        });

        Schema::table('consignment_settlement_lines', function (Blueprint $table) {
            $table->dropColumn(['sold_cost', 'returned_cost']);
        });
    }
};
