<?php

namespace App\Ledger;

use App\Models\Account;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

class LedgerReports
{
    public function __construct(private readonly TenantManager $tenant) {}

    /**
     * Trial balance as of a date: each account's net balance placed in the
     * debit or credit column. Column totals must be equal.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array{debit: float, credit: float}, as_of: string}
     */
    public function trialBalance(?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();

        $grouped = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('j.company_id', $this->tenant->id())
            ->where('j.status', 'posted')
            ->whereDate('j.entry_date', '<=', $asOf)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->select(
                'a.id', 'a.code', 'a.name', 'a.type',
                DB::raw('SUM(jl.base_debit) as debit'),
                DB::raw('SUM(jl.base_credit) as credit'),
            )
            ->orderBy('a.code')
            ->get();

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($grouped as $row) {
            $net = round((float) $row->debit - (float) $row->credit, 4);
            if (abs($net) < 0.00005) {
                continue; // suppress zero-balance accounts
            }

            $debit = $net > 0 ? $net : 0.0;
            $credit = $net < 0 ? -$net : 0.0;
            $totalDebit += $debit;
            $totalCredit += $credit;

            $rows[] = [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => ['debit' => round($totalDebit, 2), 'credit' => round($totalCredit, 2)],
            'as_of' => $asOf,
        ];
    }

    /**
     * Net movement (base debit − credit) per account for posted journals within a date
     * window. $from null means from inception.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function balances(?string $from, string $to)
    {
        $q = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('j.company_id', $this->tenant->id())
            ->where('j.status', 'posted')
            ->whereDate('j.entry_date', '<=', $to);
        if ($from) {
            $q->whereDate('j.entry_date', '>=', $from);
        }

        return $q->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.control_type')
            ->select('a.id', 'a.code', 'a.name', 'a.type', 'a.control_type', DB::raw('SUM(jl.base_debit - jl.base_credit) as net'))
            ->orderBy('a.code')->get();
    }

    /**
     * Income statement (profit & loss) for a period.
     */
    public function incomeStatement(string $from, string $to): array
    {
        $rows = $this->balances($from, $to);
        $section = fn (array $r) => ['rows' => $r, 'total' => round(array_sum(array_column($r, 'amount')), 2)];

        $revenue = $otherIncome = $cogs = $opex = $otherExp = [];
        foreach ($rows as $r) {
            $code = (string) $r->code;
            if ($r->type === 'income') {
                $amount = round(-(float) $r->net, 2); // credit-normal → positive
                if (abs($amount) < 0.005) {
                    continue;
                }
                $line = ['code' => $code, 'name' => $r->name, 'amount' => $amount];
                str_starts_with($code, '41') ? $revenue[] = $line : $otherIncome[] = $line;
            } elseif ($r->type === 'expense') {
                $amount = round((float) $r->net, 2); // debit-normal → positive
                if (abs($amount) < 0.005) {
                    continue;
                }
                $line = ['code' => $code, 'name' => $r->name, 'amount' => $amount];
                if (str_starts_with($code, '5')) {
                    $cogs[] = $line;
                } elseif (str_starts_with($code, '6')) {
                    $opex[] = $line;
                } else {
                    $otherExp[] = $line;
                }
            }
        }

        $revenueT = $section($revenue)['total'];
        $cogsT = $section($cogs)['total'];
        $opexT = $section($opex)['total'];
        $otherIncomeT = $section($otherIncome)['total'];
        $otherExpT = $section($otherExp)['total'];
        $gross = round($revenueT - $cogsT, 2);
        $operating = round($gross - $opexT, 2);
        $net = round($operating + $otherIncomeT - $otherExpT, 2);

        return [
            'from' => $from, 'to' => $to,
            'revenue' => $section($revenue), 'cost_of_sales' => $section($cogs),
            'operating_expenses' => $section($opex), 'other_income' => $section($otherIncome), 'other_expenses' => $section($otherExp),
            'gross_profit' => $gross, 'operating_profit' => $operating, 'net_profit' => $net,
        ];
    }

    /**
     * Balance sheet as of a date. Undistributed earnings (all unclosed P&L) sit in equity
     * so the statement always balances to the double-entry identity.
     */
    public function balanceSheet(string $asOf): array
    {
        $rows = $this->balances(null, $asOf);

        $assets = $liabilities = $equity = [];
        // Accumulate section totals from the RAW nets (not the rounded line amounts) so the
        // accounting identity holds to the cent even when individual lines are rounded.
        $assetsRaw = $liabRaw = $equityRaw = $earningsRaw = 0.0;
        foreach ($rows as $r) {
            $net = (float) $r->net;
            if ($r->type === 'asset') {
                $assetsRaw += $net;
                if (abs($net) >= 0.005) {
                    $assets[] = ['code' => $r->code, 'name' => $r->name, 'amount' => round($net, 2)];
                }
            } elseif ($r->type === 'liability') {
                $liabRaw += -$net;
                if (abs($net) >= 0.005) {
                    $liabilities[] = ['code' => $r->code, 'name' => $r->name, 'amount' => round(-$net, 2)];
                }
            } elseif ($r->type === 'equity') {
                $equityRaw += -$net;
                if (abs($net) >= 0.005) {
                    $equity[] = ['code' => $r->code, 'name' => $r->name, 'amount' => round(-$net, 2)];
                }
            } else { // income / expense → undistributed earnings
                $earningsRaw += -$net;
            }
        }
        if (abs($earningsRaw) >= 0.005) {
            $equity[] = ['code' => '—', 'name' => 'Undistributed earnings', 'amount' => round($earningsRaw, 2)];
        }

        $assetsT = round($assetsRaw, 2);
        $liabT = round($liabRaw, 2);
        $equityT = round($equityRaw + $earningsRaw, 2);
        // One rounding of the combined raw side; since assetsRaw == liabRaw+equityRaw+earningsRaw
        // exactly (double entry), this equals $assetsT to the cent — no drift from summing
        // separately-rounded subtotals.
        $liabEquityT = round($liabRaw + $equityRaw + $earningsRaw, 2);

        return [
            'as_of' => $asOf,
            'assets' => ['rows' => $assets, 'total' => $assetsT],
            'liabilities' => ['rows' => $liabilities, 'total' => $liabT],
            'equity' => ['rows' => $equity, 'total' => $equityT],
            'liabilities_equity_total' => $liabEquityT,
            'balanced' => abs($assetsT - $liabEquityT) < 0.01,
        ];
    }

    /**
     * Cash-flow statement (movement method): the change in cash & equivalents over a
     * period, explained by the offsetting movements on every other account, grouped
     * into operating / investing / financing.
     */
    public function cashFlow(string $from, string $to): array
    {
        $cashIds = Account::whereIn('control_type', ['cash', 'bank'])->pluck('id')->all();

        $opening = 0.0;
        if ($cashIds) {
            $row = DB::table('journal_lines as jl')->join('journals as j', 'j.id', '=', 'jl.journal_id')
                ->where('j.company_id', $this->tenant->id())->where('j.status', 'posted')
                ->whereIn('jl.account_id', $cashIds)->whereDate('j.entry_date', '<', $from)
                ->selectRaw('COALESCE(SUM(jl.base_debit - jl.base_credit),0) net')->first();
            $opening = round((float) $row->net, 2);
        }

        $operating = $investing = $financing = [];
        foreach ($this->balances($from, $to) as $r) {
            if (in_array($r->id, $cashIds)) {
                continue;
            }
            $contribution = round(-(float) $r->net, 2); // cash effect of this account's movement
            if (abs($contribution) < 0.005) {
                continue;
            }
            $line = ['code' => $r->code, 'name' => $r->name, 'amount' => $contribution];
            $code = (string) $r->code;
            if ($r->type === 'asset' && str_starts_with($code, '15')) {
                $investing[] = $line;
            } elseif ($r->type === 'equity' || ($r->type === 'liability' && str_starts_with($code, '25'))) {
                $financing[] = $line;
            } else {
                $operating[] = $line;
            }
        }

        $sec = fn (array $r) => ['rows' => $r, 'total' => round(array_sum(array_column($r, 'amount')), 2)];
        $operatingS = $sec($operating);
        $investingS = $sec($investing);
        $financingS = $sec($financing);
        $netChange = round($operatingS['total'] + $investingS['total'] + $financingS['total'], 2);

        return [
            'from' => $from, 'to' => $to,
            'operating' => $operatingS, 'investing' => $investingS, 'financing' => $financingS,
            'net_change' => $netChange,
            'opening_cash' => $opening,
            'closing_cash' => round($opening + $netChange, 2),
        ];
    }

    /**
     * Account statement (general ledger) with a running balance.
     *
     * @return array{account: array<string, mixed>, opening: float, lines: array<int, array<string, mixed>>, closing: float}
     */
    public function generalLedger(Account $account, string $from, string $to): array
    {
        $debitNormal = $account->normalBalance() === 'debit';

        $openingRow = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('jl.account_id', $account->id)
            ->where('j.status', 'posted')
            ->whereDate('j.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(jl.base_debit),0) d, COALESCE(SUM(jl.base_credit),0) c')
            ->first();

        $opening = round(((float) $openingRow->d - (float) $openingRow->c) * ($debitNormal ? 1 : -1), 4);
        $running = $opening;

        $entries = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('jl.account_id', $account->id)
            ->where('j.status', 'posted')
            ->whereDate('j.entry_date', '>=', $from)
            ->whereDate('j.entry_date', '<=', $to)
            ->orderBy('j.entry_date')
            ->orderBy('j.id')
            ->select('j.id as journal_id', 'j.number', 'j.entry_date', 'j.memo', 'jl.description', 'jl.base_debit', 'jl.base_credit')
            ->get();

        $lines = [];
        foreach ($entries as $e) {
            $movement = ((float) $e->base_debit - (float) $e->base_credit) * ($debitNormal ? 1 : -1);
            $running = round($running + $movement, 4);

            $lines[] = [
                'journal_id' => $e->journal_id,
                'number' => $e->number,
                'date' => $e->entry_date,
                'narration' => $e->description ?: $e->memo,
                'debit' => (float) $e->base_debit,
                'credit' => (float) $e->base_credit,
                'balance' => $running,
            ];
        }

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type->value],
            'opening' => $opening,
            'lines' => $lines,
            'closing' => $running,
        ];
    }
}
