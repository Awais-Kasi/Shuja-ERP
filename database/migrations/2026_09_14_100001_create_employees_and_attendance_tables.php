<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('salary_expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->string('employment_type')->default('salaried'); // salaried|wage
            $table->string('payment_method')->default('bank');      // bank|cash
            $table->string('cnic')->nullable();
            $table->string('eobi_no')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('basic_salary', 19, 4)->default(0);
            $table->decimal('house_rent', 19, 4)->default(0);
            $table->decimal('medical', 19, 4)->default(0);
            $table->decimal('conveyance', 19, 4)->default(0);
            $table->decimal('other_allowance', 19, 4)->default(0);
            $table->date('date_joined')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('status')->default('present'); // present|absent|leave|half_day|holiday
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'attendance_date'], 'attendance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('employees');
    }
};
