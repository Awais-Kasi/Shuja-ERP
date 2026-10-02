<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documents can be transacted in a foreign currency; amounts are stored in that
        // currency and fx_rate (base per 1 unit) converts them to the base ledger currency.
        foreach (['sales_invoices', 'purchase_bills'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->char('currency', 3)->nullable()->after('status');
                $t->decimal('fx_rate', 24, 10)->default(1)->after('currency');
            });
        }

        Schema::table('payments', function (Blueprint $t) {
            $t->char('currency', 3)->nullable()->after('amount');
            $t->decimal('fx_rate', 24, 10)->default(1)->after('currency');
        });
    }

    public function down(): void
    {
        foreach (['sales_invoices', 'purchase_bills'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['currency', 'fx_rate']));
        }
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['currency', 'fx_rate']));
    }
};
