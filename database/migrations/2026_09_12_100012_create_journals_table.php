<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('accounting_periods')->nullOnDelete();
            $table->string('number')->nullable();
            $table->date('entry_date');
            $table->string('type')->default('manual');   // manual|opening|sales|purchase|payment|receipt|...
            $table->string('reference')->nullable();
            $table->text('memo')->nullable();
            $table->string('status')->default('draft');   // draft|posted|void
            $table->nullableMorphs('source');             // originating document (polymorphic)
            $table->foreignId('reverses_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'status']);
            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
