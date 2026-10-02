<?php

namespace App\FixedAssets;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns fixed-asset accounting: capitalisation, straight-line depreciation runs and
 * disposal — each posting one balanced journal through the PostingEngine.
 */
class FixedAssetService
{
    private const EPSILON = 1e-9;

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    /**
     * Capitalise an asset: Dr the asset (PPE) account / Cr the funding account.
     */
    public function acquire(FixedAsset $asset, int $creditAccountId): FixedAsset
    {
        if ($asset->journal_id) {
            throw new FixedAssetException('This asset has already been capitalised.');
        }

        $credit = Account::find($creditAccountId);
        if (! $credit || ! $credit->isPostable()) {
            throw new FixedAssetException('Choose a valid funding account.');
        }

        $cost = round((float) $asset->cost, 4);
        if ($cost <= self::EPSILON) {
            throw new FixedAssetException('Asset cost must be positive.');
        }

        return DB::transaction(function () use ($asset, $credit, $cost) {
            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $asset->acquisition_date->toDateString(),
                lines: [
                    new LedgerLine(accountId: $asset->asset_account_id, debit: $cost, description: "Asset {$asset->code} capitalised", costCenterId: $asset->cost_center_id),
                    LedgerLine::credit($credit->id, $cost, "Asset {$asset->code} acquisition"),
                ],
                type: 'asset_acquisition',
                reference: $asset->code,
                memo: $asset->memo ?: "Acquisition of {$asset->name}",
                source: $asset,
            ));

            $asset->update(['journal_id' => $journal->id, 'status' => 'active']);

            return $asset;
        });
    }

    /**
     * Post one month's straight-line depreciation for every active asset in service.
     */
    public function runDepreciation(int $year, int $month, ?string $runDate = null): DepreciationRun
    {
        if ($month < 1 || $month > 12) {
            throw new FixedAssetException('Depreciation month must be between 1 and 12.');
        }
        if (DepreciationRun::where('period_year', $year)->where('period_month', $month)->exists()) {
            throw new FixedAssetException('Depreciation has already been run for this period.');
        }

        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();
        $date = $runDate ?: $periodEnd->toDateString();

        $assets = FixedAsset::where('status', 'active')
            ->whereDate('acquisition_date', '<=', $periodEnd->toDateString())
            ->orderBy('code')
            ->get();

        if ($assets->isEmpty()) {
            throw new FixedAssetException('No active assets to depreciate.');
        }

        try {
            return $this->postDepreciation($assets, $year, $month, $date);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race with a concurrent run for the same period — the unique index is
            // the real guard; surface the friendly error instead of a 500.
            throw new FixedAssetException('Depreciation has already been run for this period.');
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FixedAsset>  $assets
     */
    private function postDepreciation($assets, int $year, int $month, string $date): DepreciationRun
    {
        return DB::transaction(function () use ($assets, $year, $month, $date) {
            $run = DepreciationRun::create([
                'company_id' => $this->tenant->id(),
                'period_year' => $year,
                'period_month' => $month,
                'run_date' => $date,
                'status' => 'posted',
                'created_by' => optional(auth()->user())->id,
            ]);

            $expenseByKey = [];    // "acct:cc" => [account, cc, amount]
            $accumByAccount = [];  // accum account => amount
            $total = 0.0;

            foreach ($assets as $asset) {
                $charge = $asset->monthlyDepreciation();
                if ($charge <= self::EPSILON) {
                    continue;
                }

                $key = $asset->depreciation_account_id.':'.($asset->cost_center_id ?? 0);
                $expenseByKey[$key]['account'] = (int) $asset->depreciation_account_id;
                $expenseByKey[$key]['cc'] = $asset->cost_center_id;
                $expenseByKey[$key]['amount'] = round(($expenseByKey[$key]['amount'] ?? 0) + $charge, 4);
                $accumByAccount[$asset->accum_account_id] = round(($accumByAccount[$asset->accum_account_id] ?? 0) + $charge, 4);

                $run->lines()->create([
                    'company_id' => $this->tenant->id(),
                    'fixed_asset_id' => $asset->id,
                    'amount' => $charge,
                ]);

                $newAccum = round((float) $asset->accumulated_depreciation + $charge, 4);
                $asset->update([
                    'accumulated_depreciation' => $newAccum,
                    'status' => ($asset->depreciableBase() - $newAccum) <= self::EPSILON ? 'fully_depreciated' : 'active',
                ]);

                $total = round($total + $charge, 4);
            }

            if ($total <= self::EPSILON) {
                throw new FixedAssetException('No depreciation is due this period — all assets are fully depreciated.');
            }

            $lines = [];
            foreach ($expenseByKey as $g) {
                $lines[] = new LedgerLine(accountId: $g['account'], debit: $g['amount'], costCenterId: $g['cc'], description: 'Depreciation');
            }
            foreach ($accumByAccount as $accountId => $amount) {
                $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Accumulated depreciation');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'depreciation',
                reference: sprintf('DEP-%04d-%02d', $year, $month),
                memo: "Depreciation for {$month}/{$year}",
                source: $run,
            ));

            $run->update(['total_amount' => $total, 'journal_id' => $journal->id]);

            return $run->load('lines');
        });
    }

    /**
     * Dispose of an asset: remove cost and accumulated depreciation, book the proceeds,
     * and recognise the gain or loss versus net book value.
     */
    public function dispose(FixedAsset $asset, float $proceeds, string $date, int $cashAccountId): FixedAsset
    {
        if ($asset->status === 'disposed') {
            throw new FixedAssetException('This asset has already been disposed.');
        }
        if (! $asset->journal_id) {
            throw new FixedAssetException('This asset has not been capitalised.');
        }

        $cash = Account::find($cashAccountId);
        if (! $cash || ! $cash->isPostable()) {
            throw new FixedAssetException('Choose a valid account for the proceeds.');
        }

        $proceeds = round(max(0.0, $proceeds), 4);

        return DB::transaction(function () use ($asset, $cash, $proceeds, $date) {
            // Lock the row and re-check so two concurrent disposals cannot both post.
            $locked = FixedAsset::whereKey($asset->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'disposed') {
                throw new FixedAssetException('This asset has already been disposed.');
            }

            $cost = round((float) $asset->cost, 4);
            $accum = round((float) $asset->accumulated_depreciation, 4);
            $bookValue = round($cost - $accum, 4);
            $gainLoss = round($proceeds - $bookValue, 4); // > 0 gain, < 0 loss

            $lines = [];
            if ($accum > self::EPSILON) {
                $lines[] = LedgerLine::debit($asset->accum_account_id, $accum, 'Accumulated depreciation removed');
            }
            if ($proceeds > self::EPSILON) {
                $lines[] = LedgerLine::debit($cash->id, $proceeds, 'Disposal proceeds');
            }
            if ($gainLoss < -self::EPSILON) {
                $lossAccount = Account::where('code', '6900')->first();
                if (! $lossAccount) {
                    throw new FixedAssetException('No loss-on-disposal account (6900) is configured.');
                }
                $lines[] = LedgerLine::debit($lossAccount->id, round(-$gainLoss, 4), 'Loss on disposal');
            }

            $lines[] = LedgerLine::credit($asset->asset_account_id, $cost, 'Asset cost removed');

            if ($gainLoss > self::EPSILON) {
                $gainAccount = Account::where('code', '4200')->first();
                if (! $gainAccount) {
                    throw new FixedAssetException('No gain-on-disposal account (4200) is configured.');
                }
                $lines[] = LedgerLine::credit($gainAccount->id, $gainLoss, 'Gain on disposal');
            }

            if (count($lines) < 2) {
                throw new FixedAssetException('Nothing to post for this disposal.');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'asset_disposal',
                reference: $asset->code,
                memo: "Disposal of {$asset->name}",
                source: $asset,
            ));

            $asset->update([
                'status' => 'disposed',
                'disposed_at' => $date,
                'disposal_journal_id' => $journal->id,
            ]);

            return $asset;
        });
    }
}
