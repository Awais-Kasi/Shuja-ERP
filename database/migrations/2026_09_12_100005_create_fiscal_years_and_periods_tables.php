<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');                     // e.g. "FY 2026-2027"
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('open');  // open | closed
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'starts_on', 'ends_on']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->string('name');                     // e.g. "Jul 2026"
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('open');  // open | locked
            $table->timestamps();

            $table->index(['company_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
