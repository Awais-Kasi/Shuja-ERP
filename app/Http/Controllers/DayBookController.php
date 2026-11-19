<?php

namespace App\Http\Controllers;

use App\Models\Journal;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Aaj ka Hisaab" — a one-glance day book (roznamcha): the day's sales, expenses,
 * cash in / cash out and the list of every voucher posted that day. Printable.
 */
class DayBookController extends Controller
{
    public function index(Request $request, TenantManager $tenant): Response
    {
        $companyId = $tenant->id();
        $date = ($request->date('date') ?: Carbon::today())->toDateString();

        // Income (credit-positive) and expense (debit-positive) totals for the day.
        $byType = $companyId
            ? DB::table('journal_lines as l')
                ->join('journals as j', 'j.id', '=', 'l.journal_id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.company_id', $companyId)->where('j.status', 'posted')
                ->whereDate('j.entry_date', $date)
                ->whereIn('a.type', ['income', 'expense'])
                ->groupBy('a.type')
                ->select('a.type', DB::raw('SUM(l.base_credit - l.base_debit) as net'))
                ->pluck('net', 'a.type')
            : collect();

        $sales = round((float) ($byType['income'] ?? 0), 2);         // credit positive
        $expenses = round(-(float) ($byType['expense'] ?? 0), 2);    // expense is debit positive

        // Cash & bank movement for the day.
        $cash = $companyId
            ? DB::table('journal_lines as l')
                ->join('journals as j', 'j.id', '=', 'l.journal_id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.company_id', $companyId)->where('j.status', 'posted')
                ->whereDate('j.entry_date', $date)
                ->whereIn('a.control_type', ['cash', 'bank'])
                ->selectRaw('SUM(l.base_debit) as inflow, SUM(l.base_credit) as outflow')
                ->first()
            : null;

        $cashIn = round((float) ($cash->inflow ?? 0), 2);
        $cashOut = round((float) ($cash->outflow ?? 0), 2);

        // Roznamcha — every voucher posted on the day.
        $vouchers = $companyId
            ? Journal::where('status', 'posted')
                ->whereDate('entry_date', $date)
                ->withSum('lines as total_debit', 'base_debit')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Journal $j) => [
                    'number' => $j->number,
                    'type' => str_replace('_', ' ', (string) $j->type),
                    'memo' => $j->memo,
                    'amount' => round((float) $j->total_debit, 2),
                ])->all()
            : [];

        return Inertia::render('day-book/index', [
            'date' => $date,
            'prevDate' => Carbon::parse($date)->subDay()->toDateString(),
            'nextDate' => Carbon::parse($date)->addDay()->toDateString(),
            'isToday' => $date === Carbon::today()->toDateString(),
            'summary' => [
                'sales' => $sales,
                'expenses' => $expenses,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'net' => round($sales - $expenses, 2),
            ],
            'vouchers' => $vouchers,
        ]);
    }
}
