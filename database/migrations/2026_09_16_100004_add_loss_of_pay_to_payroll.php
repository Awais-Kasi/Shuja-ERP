<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('lop_days', 8, 2)->default(0)->after('gross_earnings');
            $table->decimal('loss_of_pay', 19, 4)->default(0)->after('lop_days');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->decimal('lop_total', 19, 4)->default(0)->after('deduction_total');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['lop_days', 'loss_of_pay']);
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('lop_total');
        });
    }
};
