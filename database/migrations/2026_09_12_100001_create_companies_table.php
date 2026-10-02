<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('code', 20)->nullable();                 // short code, e.g. SHUJA
            $table->char('base_currency', 3)->default('PKR');
            $table->string('country', 2)->nullable();               // ISO-3166 alpha-2
            $table->string('timezone')->default('Asia/Karachi');
            $table->unsignedTinyInteger('fiscal_start_month')->default(7); // July
            $table->string('tax_registration_no')->nullable();      // NTN/STRN/VAT etc.
            $table->text('address')->nullable();
            $table->jsonb('tax_config')->nullable();                // configurable tax engine settings
            $table->jsonb('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
