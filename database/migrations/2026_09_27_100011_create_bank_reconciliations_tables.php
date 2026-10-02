<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('opening_balance', 19, 4)->default(0);   // cleared balance carried from the prior reconciliation
            $table->decimal('statement_balance', 19, 4);             // ending balance per the bank statement
            $table->string('status')->default('draft');             // draft|completed
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'bank_account_id', 'statement_date']);
        });

        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('journal_line_id')->constrained('journal_lines')->restrictOnDelete();
            $table->decimal('amount', 19, 4);     // signed: base_debit - base_credit (increase to bank is positive)
            $table->timestamps();

            // A journal line may be cleared in at most one reconciliation.
            $table->unique('journal_line_id');
            $table->unique(['bank_reconciliation_id', 'journal_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
