<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->date('revaluation_date');
            $table->string('status')->default('posted');
            $table->decimal('total_gain', 19, 4)->default(0);
            $table->decimal('total_loss', 19, 4)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'revaluation_date']);
        });

        Schema::create('fx_revaluation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fx_revaluation_id')->constrained('fx_revaluations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('foreign_balance', 19, 4);   // net balance in the foreign currency
            $table->decimal('closing_rate', 24, 10);     // base per 1 unit of the foreign currency
            $table->decimal('carrying_base', 19, 4);     // base value currently recognised (historical + prior revals)
            $table->decimal('revalued_base', 19, 4);     // foreign_balance * closing_rate
            $table->decimal('adjustment', 19, 4);        // this period's incremental gain(+)/loss(-)
            $table->timestamps();

            $table->index(['company_id', 'account_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluation_lines');
        Schema::dropIfExists('fx_revaluations');
    }
};
