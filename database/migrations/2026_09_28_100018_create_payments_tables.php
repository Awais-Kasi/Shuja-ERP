<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->decimal('amount_paid', 19, 4)->default(0)->after('total');
        });
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->decimal('amount_paid', 19, 4)->default(0)->after('total');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('direction');                 // receive (customer) | pay (supplier)
            $table->nullableMorphs('party');             // customer or supplier
            $table->string('number')->nullable();
            $table->date('payment_date');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete(); // cash/bank
            $table->decimal('amount', 19, 4);
            $table->string('reference')->nullable();
            $table->string('memo')->nullable();
            $table->string('status')->default('posted'); // posted | reversed
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'direction', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->morphs('allocatable');               // sales_invoice or purchase_bill
            $table->decimal('amount', 19, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn('amount_paid'));
        Schema::table('purchase_bills', fn (Blueprint $t) => $t->dropColumn('amount_paid'));
    }
};
