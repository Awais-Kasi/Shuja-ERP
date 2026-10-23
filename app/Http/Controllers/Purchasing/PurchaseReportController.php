<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReportController extends Controller
{
    /** Register of posted supplier bills for a period, with tax and base-currency totals. */
    public function register(Request $request): Response
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();

        $bills = PurchaseBill::query()
            ->where('status', 'posted')
            ->whereDate('bill_date', '>=', $from)
            ->whereDate('bill_date', '<=', $to)
            ->with('supplier:id,code,name')
            ->orderBy('bill_date')->orderBy('id')
            ->get()
            ->map(fn (PurchaseBill $b) => [
                'number' => $b->number,
                'supplier_invoice_no' => $b->supplier_invoice_no,
                'date' => $b->bill_date->toDateString(),
                'supplier' => $b->supplier->code.' — '.$b->supplier->name,
                'currency' => $b->currency,
                'subtotal' => (float) $b->subtotal,
                'tax' => (float) $b->tax_amount,
                'total' => (float) $b->total,
                'base_total' => round((float) $b->total * ((float) $b->fx_rate ?: 1), 2),
            ]);

        return Inertia::render('purchase/purchase-register', [
            'from' => $from,
            'to' => $to,
            'rows' => $bills->values(),
            'total' => round($bills->sum('base_total'), 2),
            'count' => $bills->count(),
        ]);
    }

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

    /** Full account statement (khata) for one supplier: every payable movement with a running balance. */
    public function supplierStatement(Supplier $supplier, Request $request, TenantManager $tenant): Response
    {
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $base = fn () => DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.control_type', 'ap')
            ->where('l.party_type', (new Supplier)->getMorphClass())
            ->where('l.party_id', $supplier->id)
            ->where('j.status', 'posted')
            ->where('j.company_id', $tenant->id());

        $opening = $from
            ? round((float) $base()->whereDate('j.entry_date', '<', $from)->sum(DB::raw('l.base_credit - l.base_debit')), 2)
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
            $running = round($running + (float) $l->base_credit - (float) $l->base_debit, 2);

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

        return Inertia::render('purchase/supplier-statement', [
            'party' => ['id' => $supplier->id, 'code' => $supplier->code, 'name' => $supplier->name],
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
