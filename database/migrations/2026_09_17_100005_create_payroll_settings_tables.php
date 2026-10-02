<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->decimal('eobi_wage_base', 19, 4)->default(37000);
            $table->decimal('eobi_employee_rate', 8, 6)->default(0.01);
            $table->decimal('eobi_employer_rate', 8, 6)->default(0.05);
            $table->decimal('pf_rate', 8, 6)->default(0.0833);
            $table->timestamps();

            $table->unique('company_id');
        });

        Schema::create('payroll_tax_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->decimal('lower_bound', 19, 4);   // annual income lower bound of the band
            $table->decimal('base_tax', 19, 4);      // cumulative tax at the lower bound
            $table->decimal('rate', 8, 6);           // marginal rate above the lower bound
            $table->timestamps();

            $table->index(['company_id', 'lower_bound']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_tax_slabs');
        Schema::dropIfExists('payroll_settings');
    }
};
