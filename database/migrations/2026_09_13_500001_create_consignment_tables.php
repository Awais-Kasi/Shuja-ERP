<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A location can carry its own GL inventory control account, so consignment
        // stock reclassifies to a distinct balance-sheet line while ordinary
        // transfers stay GL-neutral. Resolver: wh.inventory_account_id ?? item.inventory_account_id.
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('inventory_account_id')->nullable()->after('cost_center_id')
                ->constrained('accounts')->nullOnDelete();
        });

        Schema::create('consignment_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('agent_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('number')->nullable();
            $table->date('dispatch_date');
            $table->string('status')->default('draft'); // draft|posted|partially_settled|settled
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('memo')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('consignment_dispatch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consignment_dispatch_id')->constrained('consignment_dispatches')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 19, 4);
            $table->decimal('dispatch_rate', 19, 4)->nullable();
            $table->decimal('dispatch_value', 19, 4)->nullable();
            $table->decimal('settled_qty', 19, 4)->default(0);
            $table->decimal('returned_qty', 19, 4)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('consignment_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consignment_dispatch_id')->constrained('consignment_dispatches')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete(); // capitalise target
            $table->foreignId('credit_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('number')->nullable();
            $table->date('expense_date');
            $table->string('allocation_basis')->default('value'); // value|quantity
            $table->nullableMorphs('party'); // carrier as Supplier when credit = AP
            $table->string('status')->default('draft');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('memo')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('consignment_expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consignment_expense_id')->constrained('consignment_expenses')->cascadeOnDelete();
            $table->string('expense_type')->default('freight'); // freight|transport|loading|labour|other
            $table->decimal('amount', 19, 4);
            $table->boolean('capitalise')->default(true);
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete(); // P&L acct when not capitalised
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('consignment_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consignment_dispatch_id')->constrained('consignment_dispatches')->cascadeOnDelete();
            $table->foreignId('consignment_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('agent_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('return_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('number')->nullable();
            $table->date('settlement_date');
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('cogs_total', 19, 4)->default(0);
            $table->string('status')->default('draft');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('memo')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('consignment_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('consignment_settlement_id')->constrained('consignment_settlements')->cascadeOnDelete();
            $table->foreignId('consignment_dispatch_line_id')->nullable()->constrained('consignment_dispatch_lines')->nullOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('sold_qty', 19, 4)->default(0);
            $table->decimal('rate', 19, 4)->default(0); // selling price for sold qty
            $table->decimal('amount', 19, 4)->default(0);
            $table->decimal('returned_qty', 19, 4)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_settlement_lines');
        Schema::dropIfExists('consignment_settlements');
        Schema::dropIfExists('consignment_expense_lines');
        Schema::dropIfExists('consignment_expenses');
        Schema::dropIfExists('consignment_dispatch_lines');
        Schema::dropIfExists('consignment_dispatches');
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_account_id');
        });
    }
};
