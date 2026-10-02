<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only movement ledger — the source of truth for stock & value.
        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('posting_date');
            $table->string('entry_type'); // opening|receipt|issue|transfer_in|transfer_out|adjustment
            $table->decimal('quantity', 19, 4);        // signed: + in, - out
            $table->decimal('rate', 19, 4);            // unit cost of this movement
            $table->decimal('value', 19, 4);           // signed movement value (quantity * rate)
            $table->decimal('balance_qty', 19, 4);     // running quantity after this entry
            $table->decimal('balance_value', 19, 4);   // running value after this entry
            $table->string('valuation_method');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->nullableMorphs('source');          // originating document
            $table->string('voucher_no')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'item_id', 'warehouse_id', 'posting_date'], 'sle_item_wh_date_idx');
            // nullableMorphs('source') already indexes (source_type, source_id)
        });

        // FIFO layers: one row per receipt, consumed oldest-first on issue.
        Schema::create('stock_fifo_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('sle_id')->nullable()->constrained('stock_ledger_entries')->nullOnDelete();
            $table->date('posting_date');
            $table->decimal('rate', 19, 4);
            $table->decimal('original_qty', 19, 4);
            $table->decimal('remaining_qty', 19, 4);
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'item_id', 'warehouse_id'], 'fifo_item_wh_idx');
        });

        // Cached current position per (item, warehouse). SLE remains source of truth.
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity', 19, 4)->default(0);
            $table->decimal('value', 19, 4)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_fifo_layers');
        Schema::dropIfExists('stock_ledger_entries');
    }
};
