<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete(); // output / finished good
            $table->string('code', 60);
            $table->string('name');
            $table->decimal('output_qty', 19, 4)->default(1); // batch size the components make
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 19, 4); // per output_qty
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_lines');
        Schema::dropIfExists('boms');
    }
};
