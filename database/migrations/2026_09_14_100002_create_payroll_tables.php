<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number')->nullable();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('accrual_date');
            $table->string('status')->default('draft'); // draft|posted|partially_paid|paid
            $table->decimal('gross_total', 19, 4)->default(0);
            $table->decimal('deduction_total', 19, 4)->default(0);
            $table->decimal('employer_contrib_total', 19, 4)->default(0);
            $table->decimal('net_total', 19, 4)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('memo')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'period_year', 'period_month']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // earnings
            $table->decimal('basic', 19, 4)->default(0);
            $table->decimal('house_rent', 19, 4)->default(0);
            $table->decimal('medical', 19, 4)->default(0);
            $table->decimal('conveyance', 19, 4)->default(0);
            $table->decimal('other_allowance', 19, 4)->default(0);
            $table->decimal('gross_earnings', 19, 4)->default(0);
            // deductions
            $table->decimal('income_tax', 19, 4)->default(0);
            $table->decimal('eobi', 19, 4)->default(0);
            $table->decimal('provident_fund', 19, 4)->default(0);
            $table->decimal('other_deduction', 19, 4)->default(0);
            $table->decimal('total_deductions', 19, 4)->default(0);
            // employer contributions
            $table->decimal('employer_eobi', 19, 4)->default(0);
            $table->decimal('employer_pf', 19, 4)->default(0);
            $table->decimal('net_pay', 19, 4)->default(0);
            $table->decimal('paid_amount', 19, 4)->default(0);
            $table->string('status')->default('posted'); // posted|paid
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('paid_from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('number')->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 19, 4)->default(0);
            $table->string('status')->default('draft'); // draft|posted
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('memo')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('payroll_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_payment_id')->constrained('payroll_payments')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('payslips')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_lines');
        Schema::dropIfExists('payroll_payments');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};
