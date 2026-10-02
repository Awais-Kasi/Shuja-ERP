<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Periods\PeriodCloseException;
use App\Periods\PeriodCloseService;
use App\Ledger\PostingException;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller
{
    public function index(TenantManager $tenant): Response
    {
        $plAccounts = Account::whereIn('type', ['income', 'expense'])->where('is_group', false)->pluck('id')->all();

        $years = FiscalYear::with(['periods' => fn ($q) => $q->orderBy('starts_on')])
            ->orderByDesc('starts_on')->get()
            ->map(function (FiscalYear $y) use ($plAccounts, $tenant) {
                $net = $plAccounts ? (float) DB::table('journal_lines as l')
                    ->join('journals as j', 'j.id', '=', 'l.journal_id')
                    ->where('l.company_id', $tenant->id())
                    ->where('j.status', 'posted')
                    ->whereIn('l.account_id', $plAccounts)
                    ->whereDate('j.entry_date', '>=', $y->starts_on->toDateString())
                    ->whereDate('j.entry_date', '<=', $y->ends_on->toDateString())
                    ->sum(DB::raw('l.base_credit - l.base_debit')) : 0.0; // credit-positive = profit

                return [
                    'id' => $y->id,
                    'name' => $y->name,
                    'starts_on' => $y->starts_on->toDateString(),
                    'ends_on' => $y->ends_on->toDateString(),
                    'status' => $y->status,
                    'closed_at' => $y->closed_at?->toDateString(),
                    'net_profit' => round($net, 2),
                    'periods' => $y->periods->map(fn (AccountingPeriod $p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'starts_on' => $p->starts_on->toDateString(),
                        'ends_on' => $p->ends_on->toDateString(),
                        'status' => $p->status,
                    ]),
                ];
            });

        return Inertia::render('accounting/periods/index', ['years' => $years]);
    }

    public function lock(AccountingPeriod $period, PeriodCloseService $service): RedirectResponse
    {
        try {
            $service->lock($period);
        } catch (PeriodCloseException $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        return back()->with('success', "Period {$period->name} locked.");
    }

    public function unlock(AccountingPeriod $period, PeriodCloseService $service): RedirectResponse
    {
        try {
            $service->reopen($period);
        } catch (PeriodCloseException $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        return back()->with('success', "Period {$period->name} reopened.");
    }

    public function closeYear(FiscalYear $fiscalYear, PeriodCloseService $service): RedirectResponse
    {
        try {
            $service->closeYear($fiscalYear);
        } catch (PeriodCloseException|PostingException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('success', "{$fiscalYear->name} closed.");
    }

    public function reopenYear(FiscalYear $fiscalYear, PeriodCloseService $service): RedirectResponse
    {
        try {
            $service->reopenYear($fiscalYear);
        } catch (PeriodCloseException|PostingException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('success', "{$fiscalYear->name} reopened.");
    }
}
