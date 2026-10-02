<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global currency reference
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();          // ISO 4217
            $table->string('name');
            $table->string('symbol', 8)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Per-company FX rates
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->char('base_code', 3);               // company base currency
            $table->char('quote_code', 3);              // foreign currency
            $table->decimal('rate', 24, 10);            // 1 quote = rate * base
            $table->date('rate_date');
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'quote_code', 'rate_date']);
            $table->unique(['company_id', 'base_code', 'quote_code', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
