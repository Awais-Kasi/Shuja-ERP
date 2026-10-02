<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignment_expenses', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('posted_at');
            $table->foreignId('reversal_journal_id')->nullable()->after('journal_id')->constrained('journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consignment_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_id');
            $table->dropColumn('reversed_at');
        });
    }
};
