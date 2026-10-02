<?php

namespace App\Recurring;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\RecurringJournal;
use App\Support\Tenancy\TenantManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Templates for journals that repeat on a schedule. Generating a template posts a
 * real balanced journal through the PostingEngine and advances the next run date,
 * catching up any missed periods up to "as of" today.
 */
class RecurringJournalService
{
    private const SCALE = 10000;

    /** Safety cap on catch-up iterations per template in a single run. */
    private const MAX_CATCHUP = 120;

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $data   name, reference?, memo?, frequency, interval, start_date, end_date?
     * @param  array<int, array{account_id:int, debit?:float, credit?:float, cost_center_id?:?int, description?:?string}>  $lines
     */
    public function create(array $data, array $lines): RecurringJournal
    {
        $this->assertBalanced($lines);

        if (! in_array($data['frequency'], self::FREQUENCIES, true)) {
            throw new RecurringJournalException('Unknown frequency.');
        }
        $interval = max(1, (int) ($data['interval'] ?? 1));

        return DB::transaction(function () use ($data, $lines, $interval) {
            $template = RecurringJournal::create([
                'company_id' => $this->tenant->id(),
                'name' => $data['name'],
                'reference' => $data['reference'] ?? null,
                'memo' => $data['memo'] ?? null,
                'frequency' => $data['frequency'],
                'interval' => $interval,
                'start_date' => $data['start_date'],
                'next_run_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'status' => 'active',
                'created_by' => optional(auth()->user())->id,
            ]);

            foreach (array_values($lines) as $i => $l) {
                $template->lines()->create([
                    'company_id' => $this->tenant->id(),
                    'account_id' => (int) $l['account_id'],
                    'cost_center_id' => $l['cost_center_id'] ?? null,
                    'line_no' => $i + 1,
                    'description' => $l['description'] ?? null,
                    'debit' => round((float) ($l['debit'] ?? 0), 4),
                    'credit' => round((float) ($l['credit'] ?? 0), 4),
                ]);
            }

            return $template->load('lines');
        });
    }

    /**
     * Generate every template that is due on or before "as of" (default today),
     * catching up missed periods. Failures (e.g. no open period) stop that one
     * template and are reported, without blocking the others.
     *
     * @return array{generated:int, errors:array<int, array{template:string, date:string, message:string}>}
     */
    public function runDue(?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();
        $generated = 0;
        $errors = [];

        $templates = RecurringJournal::where('status', 'active')
            ->whereDate('next_run_date', '<=', $asOf)
            ->orderBy('id')->get();

        foreach ($templates as $template) {
            [$count, $error] = $this->catchUp($template, $asOf);
            $generated += $count;
            if ($error) {
                $errors[] = $error;
            }
        }

        return ['generated' => $generated, 'errors' => $errors];
    }

    /**
     * Run a single template's due periods immediately.
     *
     * @return array{generated:int, errors:array<int, array{template:string, date:string, message:string}>}
     */
    public function runOne(RecurringJournal $template, ?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();
        if (! $template->isActive()) {
            throw new RecurringJournalException('Only an active template can be generated.');
        }
        [$count, $error] = $this->catchUp($template, $asOf);

        return ['generated' => $count, 'errors' => $error ? [$error] : []];
    }

    /**
     * Post one journal per due period for a template, advancing next_run_date.
     *
     * @return array{0:int, 1:?array{template:string, date:string, message:string}}
     */
    private function catchUp(RecurringJournal $template, string $asOf): array
    {
        $count = 0;
        $guard = 0;

        while ($guard++ < self::MAX_CATCHUP) {
            $dueRef = null;
            try {
                // The lock, the due re-check, the post and the advance all live in ONE
                // transaction. A SELECT ... FOR UPDATE outside a transaction commits (and
                // releases) immediately, so it must be taken here — this way a competing
                // run blocks on the row, then re-reads the already-advanced next_run_date
                // and stops, instead of double-posting the same period.
                $posted = DB::transaction(function () use ($template, $asOf, &$dueRef) {
                    $fresh = RecurringJournal::whereKey($template->id)->lockForUpdate()->first();
                    if (! $fresh || ! $fresh->isActive()) {
                        return false;
                    }
                    $due = $fresh->next_run_date->toDateString();
                    if ($due > $asOf) {
                        return false;
                    }
                    if ($fresh->end_date && $due > $fresh->end_date->toDateString()) {
                        $fresh->update(['status' => 'ended']);

                        return false;
                    }

                    $dueRef = $due;
                    $this->generate($fresh, $due);
                    $next = $this->advance($fresh->next_run_date, $fresh->frequency, (int) $fresh->interval);
                    $status = ($fresh->end_date && $next->gt($fresh->end_date)) ? 'ended' : 'active';
                    $fresh->update([
                        'next_run_date' => $next->toDateString(),
                        'last_generated_at' => now(),
                        'status' => $status,
                    ]);

                    return true;
                });
            } catch (PostingException $e) {
                // The transaction rolled back, so next_run_date still sits on the failing
                // period; it can be retried once the period is opened. Stop this template.
                return [$count, ['template' => $template->name, 'date' => $dueRef ?? $template->next_run_date->toDateString(), 'message' => $e->getMessage()]];
            }

            if (! $posted) {
                break;
            }
            $count++;
        }

        return [$count, null];
    }

    public function generate(RecurringJournal $template, string $date): Journal
    {
        $template->loadMissing('lines');
        $lines = $template->lines->map(fn ($l) => new LedgerLine(
            accountId: (int) $l->account_id,
            debit: (float) $l->debit,
            credit: (float) $l->credit,
            costCenterId: $l->cost_center_id,
            description: $l->description,
        ))->all();

        return $this->posting->post(new LedgerEntry(
            entryDate: $date,
            lines: $lines,
            type: 'recurring',
            reference: $template->reference,
            memo: $template->memo ?: $template->name,
            source: $template,
        ));
    }

    public function pause(RecurringJournal $template): void
    {
        if ($template->status === 'ended') {
            throw new RecurringJournalException('An ended template cannot be paused.');
        }
        $template->update(['status' => 'paused']);
    }

    public function resume(RecurringJournal $template): void
    {
        if ($template->status !== 'paused') {
            throw new RecurringJournalException('Only a paused template can be resumed.');
        }
        $template->update(['status' => 'active']);
    }

    private function advance(CarbonInterface $date, string $frequency, int $interval): CarbonInterface
    {
        return match ($frequency) {
            'weekly' => $date->addWeeks($interval),
            'quarterly' => $date->addMonthsNoOverflow(3 * $interval),
            'yearly' => $date->addYearsNoOverflow($interval),
            default => $date->addMonthsNoOverflow($interval),
        };
    }

    /**
     * @param  array<int, array{account_id:int, debit?:float, credit?:float}>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new RecurringJournalException('A recurring journal needs at least two lines.');
        }

        $accountIds = array_map(fn ($l) => (int) $l['account_id'], $lines);
        $postable = Account::whereIn('id', $accountIds)->get()->keyBy('id');

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lines as $l) {
            $account = $postable->get((int) $l['account_id']);
            if (! $account || ! $account->isPostable()) {
                throw new RecurringJournalException('Every line must target a postable account.');
            }
            $debit = round((float) ($l['debit'] ?? 0), 4);
            $credit = round((float) ($l['credit'] ?? 0), 4);
            if (($debit > 0) === ($credit > 0)) {
                throw new RecurringJournalException('Each line must carry exactly one of a debit or a credit amount.');
            }
            $totalDebit += (int) round($debit * self::SCALE);
            $totalCredit += (int) round($credit * self::SCALE);
        }

        if ($totalDebit !== $totalCredit) {
            throw new RecurringJournalException('The template is out of balance: debits must equal credits.');
        }
        if ($totalDebit === 0) {
            throw new RecurringJournalException('The template amount cannot be zero.');
        }
    }
}
