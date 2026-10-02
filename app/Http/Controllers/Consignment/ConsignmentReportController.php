<?php

namespace App\Http\Controllers\Consignment;

use App\Http\Controllers\Controller;
use App\Models\StockBalance;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ConsignmentReportController extends Controller
{
    public function stock(TenantManager $tenant): Response
    {
        $rows = StockBalance::query()
            ->with(['item:id,code,name', 'warehouse:id,code,name,type'])
            ->whereHas('warehouse', fn ($q) => $q->whereIn('type', ['consignment', 'transit']))
            ->where(fn ($q) => $q->where('quantity', '<>', 0)->orWhere('value', '<>', 0))
            ->get()
            ->sortBy(fn ($b) => $b->warehouse->code.$b->item->code)
            ->values()
            ->map(fn (StockBalance $b) => [
                'warehouse' => $b->warehouse->code.' — '.$b->warehouse->name,
                'item' => $b->item->code.' — '.$b->item->name,
                'quantity' => (float) $b->quantity,
                'value' => (float) $b->value,
                'rate' => $b->averageRate(),
            ]);

        $stockValue = round($rows->sum('value'), 2);

        $glValue = round((float) DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.company_id', $tenant->id())
            ->where('j.status', 'posted')
            ->whereIn('a.code', ['1124', '1140'])
            ->sum(DB::raw('l.base_debit - l.base_credit')), 2);

        return Inertia::render('consignment/stock', [
            'rows' => $rows,
            'stockValue' => $stockValue,
            'glValue' => $glValue,
            'reconciled' => abs($stockValue - $glValue) < 0.01,
        ]);
    }
}
