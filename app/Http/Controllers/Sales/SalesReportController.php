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

    /** Full account statement (khata) for one customer: every receivable movement with a running balance. */
    public function customerStatement(Customer $customer, Request $request, TenantManager $tenant): Response
    {
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $base = fn () => DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.control_type', 'ar')
            ->where('l.party_type', (new Customer)->getMorphClass())
            ->where('l.party_id', $customer->id)
            ->where('j.status', 'posted')
            ->where('j.company_id', $tenant->id());

        $opening = $from
            ? round((float) $base()->whereDate('j.entry_date', '<', $from)->sum(DB::raw('l.base_debit - l.base_credit')), 2)
            : 0.0;

        $q = $base();
        if ($from) {
            $q->whereDate('j.entry_date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('j.entry_date', '<=', $to);
        }
        $lines = $q->orderBy('j.entry_date')->orderBy('j.id')
            ->select('j.id as journal_id', 'j.entry_date', 'j.number', 'j.reference', 'j.type', 'l.description', 'l.base_debit', 'l.base_credit')
            ->get();

        $running = $opening;
        $rows = $lines->map(function ($l) use (&$running) {
            $running = round($running + (float) $l->base_debit - (float) $l->base_credit, 2);

            return [
                'date' => $l->entry_date,
                'journal_id' => $l->journal_id,
                'voucher' => $l->number ?: $l->reference,
                'detail' => $l->description ?: ucfirst((string) $l->type),
                'debit' => round((float) $l->base_debit, 2),
                'credit' => round((float) $l->base_credit, 2),
                'balance' => $running,
            ];
        });

        return Inertia::render('sales/customer-statement', [
            'party' => ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name],
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $running,
            'totalDebit' => round((float) $lines->sum('base_debit'), 2),
            'totalCredit' => round((float) $lines->sum('base_credit'), 2),
            'from' => $from,
            'to' => $to,
        ]);
    }
}
