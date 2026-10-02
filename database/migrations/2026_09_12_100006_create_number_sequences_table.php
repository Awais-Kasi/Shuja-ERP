<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('key');                      // e.g. "sales_invoice"
            $table->string('prefix')->nullable();       // e.g. "INV-"
            $table->string('suffix')->nullable();
            $table->unsignedTinyInteger('padding')->default(5);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('reset_yearly')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
