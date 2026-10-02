<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('category')->nullable();
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accum_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->decimal('cost', 19, 4);
            $table->decimal('salvage_value', 19, 4)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('depreciation_method')->default('straight_line');
            $table->date('acquisition_date');
            $table->decimal('accumulated_depreciation', 19, 4)->default(0);
            $table->string('status')->default('active'); // active|fully_depreciated|disposed
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('disposal_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->date('disposed_at')->nullable();
            $table->string('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('run_date');
            $table->string('status')->default('posted');
            $table->decimal('total_amount', 19, 4)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'period_year', 'period_month']);
        });

        Schema::create('depreciation_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('depreciation_run_id')->constrained('depreciation_runs')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_run_lines');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_assets');
    }
};
