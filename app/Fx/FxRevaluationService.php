<?php

namespace App\Fx;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\FxRevaluation;
use App\Models\FxRevaluationLine;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Period-end revaluation of open foreign-currency monetary balances (cash, bank, AR, AP)
 * to a closing rate, posting the incremental unrealised FX gain/loss to the ledger.
 *
 * Each run posts only the movement since the prior revaluation (tracked per account +
 * currency), so repeated month-end runs never double-count. Realised FX on settlement is
 * recognised at transaction time and is out of this module's scope.
 */
class FxRevaluationService
{
    private const EPSILON = 1e-4;

    /** Only monetary accounts carry FX exposure worth revaluing. */
    public const MONETARY = ['cash', 'bank', 'ar', 'ap'];

    private const FX_ACCOUNT_CODE = '4400';

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    /**
     * Compute the revaluation position for every monetary account holding a non-base
     * currency balance as at $date. Pure read — nothing is posted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function computeRows(string $date): array
    {
        $company = $this->tenant->get();
        $base = $company->base_currency;
        $companyId = $company->id;

        $balances = DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)
            ->where('j.status', 'posted')
            ->whereDate('j.entry_date', '<=', $date)
            ->where('l.currency', '!=', $base)
            ->whereIn('a.control_type', self::MONETARY)
            ->groupBy('l.account_id', 'l.currency', 'a.code', 'a.name')
            ->select('l.account_id', 'l.currency', 'a.code', 'a.name',
                DB::raw('SUM(l.debit - l.credit) as fgn_bal'),
                DB::raw('SUM(l.base_debit - l.base_credit) as fgn_base'))
            ->get();

        // Cumulative adjustment already recognised per account + currency (company-scoped via Eloquent).
        $alreadyPosted = [];
        foreach (FxRevaluationLine::query()->selectRaw('account_id, currency, SUM(adjustment) as adj')->groupBy('account_id', 'currency')->get() as $r) {
            $alreadyPosted[$r->account_id.':'.$r->currency] = (float) $r->adj;
        }

        $rows = [];
        foreach ($balances as $b) {
            $fgnBal = round((float) $b->fgn_bal, 4);
            $fgnBase = round((float) $b->fgn_base, 4);
            $rate = $this->rateFor($b->currency, $base, $date);

            $prior = $alreadyPosted[$b->account_id.':'.$b->currency] ?? 0.0;
            $carrying = round($fgnBase + $prior, 4);
            $revalued = $rate !== null ? round($fgnBal * $rate, 4) : null;
            $adjustment = $revalued !== null ? round($revalued - $carrying, 4) : null;

            $rows[] = [
                'account_id' => (int) $b->account_id,
                'code' => $b->code,
                'name' => $b->name,
                'currency' => $b->currency,
                'foreign_balance' => $fgnBal,
                'closing_rate' => $rate,
                'carrying_base' => $carrying,
                'revalued_base' => $revalued,
                'adjustment' => $adjustment,
                'missing_rate' => $rate === null,
            ];
        }

        usort($rows, fn ($a, $c) => [$a['currency'], $a['code']] <=> [$c['currency'], $c['code']]);

        return $rows;
    }

    /**
     * Post the incremental unrealised FX gain/loss for $date as one balanced journal.
     */
    public function run(string $date): FxRevaluation
    {
        // Normalise so a non-padded date can't sort wrong against stored dates.
        $date = \Illuminate\Support\Carbon::parse($date)->toDateString();

        $latest = FxRevaluation::orderByDesc('revaluation_date')->orderByDesc('id')->first();
        if ($latest && $date <= $latest->revaluation_date->toDateString()) {
            throw new FxRevaluationException('The revaluation date must be after the last revaluation ('.$latest->revaluation_date->toDateString().').');
        }

        $rows = $this->computeRows($date);

        $missing = collect($rows)->filter(fn ($r) => $r['missing_rate'] && abs($r['foreign_balance']) > self::EPSILON)
            ->pluck('currency')->unique()->values();
        if ($missing->isNotEmpty()) {
            throw new FxRevaluationException('No exchange rate on or before '.$date.' for: '.$missing->implode(', ').'. Enter a closing rate first.');
        }

        $effective = array_values(array_filter($rows, fn ($r) => $r['adjustment'] !== null && abs($r['adjustment']) > self::EPSILON));
        if (! $effective) {
            throw new FxRevaluationException('Nothing to revalue — all foreign balances already carry their closing value.');
        }

        $fxAccount = Account::where('code', self::FX_ACCOUNT_CODE)->first();
        if (! $fxAccount || ! $fxAccount->isPostable()) {
            throw new FxRevaluationException('No postable Foreign Exchange Gain/(Loss) account ('.self::FX_ACCOUNT_CODE.') is configured.');
        }

        try {
            return DB::transaction(function () use ($date, $effective, $fxAccount) {
                $revaluation = FxRevaluation::create([
                    'company_id' => $this->tenant->id(),
                    'revaluation_date' => $date,
                    'status' => 'posted',
                    'created_by' => optional(auth()->user())->id,
                ]);

                $lines = [];
                $grossGain = 0.0;
                $grossLoss = 0.0;

                foreach ($effective as $r) {
                    $delta = round($r['adjustment'], 4);
                    if ($delta > 0) {
                        $lines[] = LedgerLine::debit($r['account_id'], $delta, "FX revaluation {$r['currency']}");
                        $grossGain = round($grossGain + $delta, 4);
                    } else {
                        $lines[] = LedgerLine::credit($r['account_id'], -$delta, "FX revaluation {$r['currency']}");
                        $grossLoss = round($grossLoss - $delta, 4);
                    }

                    $revaluation->lines()->create([
                        'company_id' => $this->tenant->id(),
                        'account_id' => $r['account_id'],
                        'currency' => $r['currency'],
                        'foreign_balance' => $r['foreign_balance'],
                        'closing_rate' => $r['closing_rate'],
                        'carrying_base' => $r['carrying_base'],
                        'revalued_base' => $r['revalued_base'],
                        'adjustment' => $delta,
                    ]);
                }

                // Offset each account movement against the FX P&L account: gains credit it,
                // losses debit it. Both may appear when some balances rose and others fell.
                if ($grossGain > self::EPSILON) {
                    $lines[] = LedgerLine::credit($fxAccount->id, $grossGain, 'Unrealised FX gain');
                }
                if ($grossLoss > self::EPSILON) {
                    $lines[] = LedgerLine::debit($fxAccount->id, $grossLoss, 'Unrealised FX loss');
                }

                $journal = $this->posting->post(new LedgerEntry(
                    entryDate: $date,
                    lines: $lines,
                    type: 'fx_revaluation',
                    reference: 'FXR-'.$date,
                    memo: "Foreign exchange revaluation {$date}",
                    source: $revaluation,
                ));

                $revaluation->update([
                    'journal_id' => $journal->id,
                    'total_gain' => $grossGain,
                    'total_loss' => $grossLoss,
                ]);

                return $revaluation->load('lines');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new FxRevaluationException('A revaluation for '.$date.' already exists.');
        }
    }

    /** Latest closing rate (base per 1 unit of $currency) on or before $date, or null. */
    private function rateFor(string $currency, string $base, string $date): ?float
    {
        $rate = ExchangeRate::where('quote_code', $currency)
            ->where('base_code', $base)
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')->orderByDesc('id')
            ->value('rate');

        return $rate !== null ? (float) $rate : null;
    }
}
