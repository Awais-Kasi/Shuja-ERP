<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily-wage (dihari) workers paid per unit of work (e.g. per KG), separate
 * from the monthly salaried payroll. Each worker has a running wallet:
 *   - wage_entries  = work done (earned, raises what we owe the worker)
 *   - wage_payments = money given (daily/weekly/on demand, lowers what we owe)
 * Both post to the ledger (Wages expense / Wages Payable, stamped to the worker)
 * so the wallet balance == the worker's payable, and wages hit Profit & Loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wage_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('cnic')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('rate_per_kg', 19, 4)->nullable();   // default piece rate
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('wage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('wage_worker_id')->constrained('wage_workers')->cascadeOnDelete();
            $table->string('number')->nullable();
            $table->date('entry_date');
            $table->decimal('quantity', 19, 4);     // units of work, e.g. KG
            $table->decimal('rate', 19, 4);         // per unit
            $table->decimal('amount', 19, 4);       // quantity * rate (earned)
            $table->string('description')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'wage_worker_id']);
        });

        Schema::create('wage_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('wage_worker_id')->constrained('wage_workers')->cascadeOnDelete();
            $table->string('number')->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 19, 4);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete(); // cash/bank paid from
            $table->string('reference')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'wage_worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wage_payments');
        Schema::dropIfExists('wage_entries');
        Schema::dropIfExists('wage_workers');
    }
};
