<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('journal_id')->constrained('journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->unsignedInteger('line_no')->default(1);
            $table->string('description')->nullable();

            // Transaction-currency amounts
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->char('currency', 3);
            $table->decimal('fx_rate', 24, 10)->default(1);

            // Base-currency amounts (what the ledger balances on)
            $table->decimal('base_debit', 19, 4)->default(0);
            $table->decimal('base_credit', 19, 4)->default(0);

            // Sub-ledger link (AR/AP): customer|supplier|employee
            $table->nullableMorphs('party');

            $table->timestamps();

            $table->index(['company_id', 'account_id']);
            $table->index('journal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
