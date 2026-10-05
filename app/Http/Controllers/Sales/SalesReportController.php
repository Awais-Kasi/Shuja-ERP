<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SalesReportController extends Controller
{
    /** Register of posted sales invoices for a period, with tax and base-currency totals. */
    public function register(Request $request): Response
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();

        $invoices = SalesInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->with('customer:id,code,name')
            ->orderBy('invoice_date')->orderBy('id')
            ->get()
            ->map(fn (SalesInvoice $i) => [
                'number' => $i->number,
                'date' => $i->invoice_date->toDateString(),
                'customer' => $i->customer->code.' — '.$i->customer->name,
                'currency' => $i->currency,
                'subtotal' => (float) $i->subtotal,
                'tax' => (float) $i->tax_amount,
                'total' => (float) $i->total,
                'base_total' => round((float) $i->total * ((float) $i->fx_rate ?: 1), 2),
            ]);

        return Inertia::render('sales/sales-register', [
            'from' => $from,
            'to' => $to,
            'rows' => $invoices->values(),
            'total' => round($invoices->sum('base_total'), 2),
            'count' => $invoices->count(),
        ]);
    }

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
