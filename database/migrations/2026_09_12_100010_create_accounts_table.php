<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type');                     // asset|liability|equity|income|expense
            $table->boolean('is_group')->default(false); // header vs postable leaf
            $table->string('control_type')->nullable(); // ar|ap|inventory|bank|cash|tax|retained_earnings
            $table->char('currency', 3)->nullable();    // for currency-specific accounts; null = base
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'control_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
