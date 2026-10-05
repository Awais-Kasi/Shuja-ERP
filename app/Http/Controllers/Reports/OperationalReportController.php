<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Reports\AgingReports;
use App\Reports\InventoryReports;
use App\Reports\TaxReports;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationalReportController extends Controller
{
    /** The reports hub — a single page linking every report, grouped by module. */
    public function hub(): Response
    {
        return Inertia::render('reports/index');
    }

    public function agedReceivables(Request $request, AgingReports $reports): Response
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        return Inertia::render('reports/aged-receivables', ['report' => $reports->receivables($asOf)]);
    }

    public function agedPayables(Request $request, AgingReports $reports): Response
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        return Inertia::render('reports/aged-payables', ['report' => $reports->payables($asOf)]);
    }

    public function inventoryValuation(Request $request, InventoryReports $reports): Response
    {
        $warehouseId = $request->integer('warehouse_id') ?: null;

        return Inertia::render('reports/inventory-valuation', [
            'report' => $reports->valuation($warehouseId),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => ['warehouse_id' => $warehouseId],
        ]);
    }

    public function stockAging(Request $request, InventoryReports $reports): Response
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        return Inertia::render('reports/stock-aging', ['report' => $reports->aging($asOf)]);
    }

    public function salesTax(Request $request, TaxReports $reports): Response
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->endOfMonth()->toDateString();

        return Inertia::render('reports/sales-tax', [
            'report' => $reports->salesTax($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }
}
