<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bom_id')->nullable()->constrained('boms')->nullOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();       // output FG
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete(); // RM issued from
            $table->foreignId('target_warehouse_id')->constrained('warehouses')->restrictOnDelete(); // FG received into
            $table->foreignId('overhead_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('number')->nullable();
            $table->date('order_date');
            $table->decimal('quantity', 19, 4);           // FG to produce
            $table->string('status')->default('draft');   // draft|in_progress|completed|cancelled
            $table->decimal('material_cost', 19, 4)->default(0);
            $table->decimal('overhead_cost', 19, 4)->default(0);
            $table->decimal('produced_cost', 19, 4)->default(0);
            $table->foreignId('issue_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('completion_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('work_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 19, 4);           // required
            $table->decimal('issued_qty', 19, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_lines');
        Schema::dropIfExists('work_orders');
    }
};
