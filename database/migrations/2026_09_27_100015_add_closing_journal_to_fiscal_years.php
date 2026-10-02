<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->foreignId('closing_journal_id')->nullable()->after('status')->constrained('journals')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closing_journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closing_journal_id');
            $table->dropColumn('closed_at');
        });
    }
};
