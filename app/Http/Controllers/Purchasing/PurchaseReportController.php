<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReportController extends Controller
{
    public function supplierLedger(TenantManager $tenant): Response
    {
        $rows = DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('suppliers as s', 's.id', '=', 'l.party_id')
            ->where('a.control_type', 'ap')
            ->where('l.party_type', (new Supplier)->getMorphClass())
            ->where('j.status', 'posted')
            ->where('j.company_id', $tenant->id())
            ->groupBy('s.id', 's.code', 's.name')
            ->select('s.id', 's.code', 's.name', DB::raw('SUM(l.base_credit - l.base_debit) as payable'))
            ->orderBy('s.code')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'payable' => round((float) $r->payable, 2),
            ])
            ->filter(fn ($r) => abs($r['payable']) > 0.005)
            ->values();

        return Inertia::render('purchase/supplier-ledger', [
            'rows' => $rows,
            'total' => round($rows->sum('payable'), 2),
        ]);
    }
}
