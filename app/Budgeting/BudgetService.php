<?php

namespace App\Budgeting;

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Owns budget definition and budget-vs-actual variance reporting.
 *
 * Budgets are pure planning data — they never post to the ledger. Actuals are read
 * back from posted journals for the budget's fiscal year and compared line by line.
 */
class BudgetService
{
    public function __construct(private readonly TenantManager $tenant) {}

    public function createBudget(int $fiscalYearId, string $name): Budget
    {
        $fy = FiscalYear::find($fiscalYearId);
        if (! $fy) {
            throw new BudgetException('Choose a valid fiscal year.');
        }

        if (Budget::where('fiscal_year_id', $fiscalYearId)->where('name', $name)->exists()) {
            throw new BudgetException('A budget with that name already exists for this fiscal year.');
        }

        return Budget::create([
            'company_id' => $this->tenant->id(),
            'fiscal_year_id' => $fiscalYearId,
            'name' => $name,
            'status' => 'active',
            'created_by' => optional(auth()->user())->id,
        ]);
    }

    /**
     * Set (create or overwrite) the annual budget for one account (+ optional cost center).
     */
    public function setLine(Budget $budget, int $accountId, ?int $costCenterId, float $annualAmount): BudgetLine
    {
        $account = Account::find($accountId);
        if (! $account || ! $account->isPostable() || ! in_array($account->type->value, ['income', 'expense'], true)) {
            throw new BudgetException('Budgets can only target postable income or expense accounts.');
        }

        // Serialise writes to a budget on its own row so a concurrent save of the same
        // account+cost-centre can't create a duplicate line (updateOrCreate is not atomic).
        return DB::transaction(function () use ($budget, $accountId, $costCenterId, $annualAmount) {
            Budget::whereKey($budget->id)->lockForUpdate()->first();

            return BudgetLine::updateOrCreate(
                ['budget_id' => $budget->id, 'account_id' => $accountId, 'cost_center_id' => $costCenterId],
                ['company_id' => $this->tenant->id(), 'annual_amount' => round($annualAmount, 4)],
            );
        });
    }

    public function removeLine(Budget $budget, int $lineId): void
    {
        BudgetLine::where('budget_id', $budget->id)->whereKey($lineId)->delete();
    }

    /**
     * Budget-vs-actual for every line, pro-rated to the elapsed months of the year.
     *
     * @return array{as_of: string, months: int, rows: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function variance(Budget $budget, int $asOfMonth): array
    {
        $fy = $budget->fiscalYear;
        $months = max(1, min(12, $asOfMonth));

        $periods = $fy->periods()->orderBy('starts_on')->get();
        $cutoff = $periods->isNotEmpty()
            ? $periods[min($months, $periods->count()) - 1]->ends_on->toDateString()
            : $fy->ends_on->toDateString();
        $start = $fy->starts_on->toDateString();

        $lines = $budget->lines()->with('account:id,code,name,type', 'costCenter:id,code,name')->get();

        // Two aggregates over the posted ledger in [start, cutoff]: per account, and per
        // (account, cost center) — so account-only lines and cost-centre lines both resolve.
        $accountIds = $lines->pluck('account_id')->unique()->values()->all();
        $byAccount = [];
        $byAccountCc = [];
        if ($accountIds) {
            $base = DB::table('journal_lines as l')
                ->join('journals as j', 'j.id', '=', 'l.journal_id')
                ->where('j.status', 'posted')
                ->whereIn('l.account_id', $accountIds)
                ->whereDate('j.entry_date', '>=', $start)
                ->whereDate('j.entry_date', '<=', $cutoff);

            foreach ((clone $base)->groupBy('l.account_id')->select('l.account_id', DB::raw('SUM(l.base_debit - l.base_credit) as bal'))->get() as $r) {
                $byAccount[(int) $r->account_id] = (float) $r->bal;
            }
            foreach ((clone $base)->whereNotNull('l.cost_center_id')->groupBy('l.account_id', 'l.cost_center_id')->select('l.account_id', 'l.cost_center_id', DB::raw('SUM(l.base_debit - l.base_credit) as bal'))->get() as $r) {
                $byAccountCc[$r->account_id.':'.$r->cost_center_id] = (float) $r->bal;
            }
        }

        $rows = [];
        $totBudgetYtd = $totBudgetAnnual = $totActual = 0.0;

        foreach ($lines as $line) {
            $type = $line->account->type;                 // AccountType enum
            $creditNormal = ! $type->isDebitNormal();

            $signed = $line->cost_center_id
                ? ($byAccountCc[$line->account_id.':'.$line->cost_center_id] ?? 0.0)
                : ($byAccount[$line->account_id] ?? 0.0);
            $actual = round($creditNormal ? -$signed : $signed, 4);

            $annual = round((float) $line->annual_amount, 4);
            $budgetYtd = round($annual * $months / 12, 4);
            $variance = round($actual - $budgetYtd, 4);
            // Favourable when income beats plan or spend is under plan.
            $favorable = $type->value === 'income' ? $variance >= 0 : $variance <= 0;

            $rows[] = [
                'id' => $line->id,
                'account' => $line->account->code.' — '.$line->account->name,
                'type' => $type->value,
                'cost_center' => $line->costCenter?->code,
                'annual_budget' => $annual,
                'budget_ytd' => $budgetYtd,
                'actual_ytd' => $actual,
                'variance' => $variance,
                'variance_pct' => $budgetYtd != 0.0 ? round($variance / abs($budgetYtd) * 100, 1) : null,
                'favorable' => $favorable,
            ];

            $totBudgetAnnual += $annual;
            $totBudgetYtd += $budgetYtd;
            $totActual += $actual;
        }

        return [
            'as_of' => $cutoff,
            'months' => $months,
            'rows' => $rows,
            'totals' => [
                'annual_budget' => round($totBudgetAnnual, 2),
                'budget_ytd' => round($totBudgetYtd, 2),
                'actual_ytd' => round($totActual, 2),
                'variance' => round($totActual - $totBudgetYtd, 2),
            ],
        ];
    }
}
