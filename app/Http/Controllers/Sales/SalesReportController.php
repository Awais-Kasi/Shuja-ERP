<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SalesReportController extends Controller
{
    public function customerLedger(TenantManager $tenant): Response
    {
        $rows = DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('customers as c', 'c.id', '=', 'l.party_id')
            ->where('a.control_type', 'ar')
            ->where('l.party_type', (new Customer)->getMorphClass())
            ->where('j.status', 'posted')
            ->where('j.company_id', $tenant->id())
            ->groupBy('c.id', 'c.code', 'c.name')
            ->select('c.id', 'c.code', 'c.name', DB::raw('SUM(l.base_debit - l.base_credit) as receivable'))
            ->orderBy('c.code')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'receivable' => round((float) $r->receivable, 2)])
            ->filter(fn ($r) => abs($r['receivable']) > 0.005)
            ->values();

        return Inertia::render('sales/customer-ledger', [
            'rows' => $rows,
            'total' => round($rows->sum('receivable'), 2),
        ]);
    }
}
