<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Ledger\LedgerReports;
use App\Models\Account;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function trialBalance(Request $request, LedgerReports $reports): Response
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        return Inertia::render('accounting/reports/trial-balance', [
            'report' => $reports->trialBalance($asOf),
        ]);
    }

    public function generalLedger(Request $request, LedgerReports $reports): Response
    {
        $accounts = Account::query()
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name]);

        $accountId = $request->integer('account_id') ?: null;
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();

        $statement = null;
        if ($accountId) {
            $account = Account::findOrFail($accountId);
            $statement = $reports->generalLedger($account, $from, $to);
        }

        return Inertia::render('accounting/reports/general-ledger', [
            'accounts' => $accounts,
            'filters' => ['account_id' => $accountId, 'from' => $from, 'to' => $to],
            'statement' => $statement,
        ]);
    }

    public function incomeStatement(Request $request, LedgerReports $reports): Response
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();

        return Inertia::render('accounting/reports/income-statement', [
            'report' => $reports->incomeStatement($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function balanceSheet(Request $request, LedgerReports $reports): Response
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        return Inertia::render('accounting/reports/balance-sheet', [
            'report' => $reports->balanceSheet($asOf),
        ]);
    }

    public function cashFlow(Request $request, LedgerReports $reports): Response
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->toDateString();

        return Inertia::render('accounting/reports/cash-flow', [
            'report' => $reports->cashFlow($from, $to),
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }
}
