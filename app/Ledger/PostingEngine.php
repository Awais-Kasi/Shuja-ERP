<?php

namespace App\Ledger;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Journal;
use App\Models\NumberSequence;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The single component allowed to write journals. Every module posts through
 * here so that the ledger invariants — balanced, atomic, into an open period,
 * against postable accounts — hold in exactly one place.
 */
class PostingEngine
{
    /** Money is compared in integer units of 1/10000 to avoid float drift. */
    private const SCALE = 10000;

    public function __construct(private readonly TenantManager $tenant) {}

    public function postDocument(PostsToLedger $document): Journal
    {
        return $this->post($document->toLedgerEntry());
    }

    public function post(LedgerEntry $entry): Journal
    {
        $company = $this->tenant->get();
        if (! $company) {
            throw new PostingException('No active company is bound to the request.');
        }
        $companyId = $company->id;
        $baseCurrency = $company->base_currency;

        if (count($entry->lines) < 2) {
            throw new PostingException('A journal entry needs at least two lines.');
        }

        $period = $this->resolvePeriod($entry->entryDate);

        $accounts = Account::query()
            ->whereIn('id', array_map(fn (LedgerLine $l) => $l->accountId, $entry->lines))
            ->get()
            ->keyBy('id');

        $prepared = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($entry->lines as $index => $line) {
            $account = $accounts->get($line->accountId);
            if (! $account) {
                throw new PostingException("Account #{$line->accountId} does not exist in this company.");
            }
            if (! $account->isPostable()) {
                throw new PostingException("Account {$account->code} — {$account->name} is a group/inactive account and cannot be posted to.");
            }

            $debit = round((float) $line->debit, 4);
            $credit = round((float) $line->credit, 4);

            if (($debit > 0) === ($credit > 0)) {
                throw new PostingException('Each line must carry exactly one of a debit or a credit amount.');
            }

            $rate = (float) $line->fxRate ?: 1.0;
            $baseDebit = round($debit * $rate, 4);
            $baseCredit = round($credit * $rate, 4);

            $totalDebit += (int) round($baseDebit * self::SCALE);
            $totalCredit += (int) round($baseCredit * self::SCALE);

            $prepared[] = [
                'company_id' => $companyId,
                'account_id' => $account->id,
                'cost_center_id' => $line->costCenterId,
                'line_no' => $index + 1,
                'description' => $line->description,
                'debit' => $debit,
                'credit' => $credit,
                'currency' => $line->currency ?: $baseCurrency,
                'fx_rate' => $rate,
                'base_debit' => $baseDebit,
                'base_credit' => $baseCredit,
                'party_type' => $line->partyType,
                'party_id' => $line->partyId,
            ];
        }

        if ($totalDebit !== $totalCredit) {
            $d = number_format($totalDebit / self::SCALE, 2);
            $c = number_format($totalCredit / self::SCALE, 2);
            throw new PostingException("Journal is out of balance: debits {$d} ≠ credits {$c}.");
        }

        return DB::transaction(function () use ($entry, $companyId, $period, $prepared) {
            $journal = Journal::create([
                'company_id' => $companyId,
                'period_id' => $period->id,
                'number' => $this->allocateNumber($companyId),
                'entry_date' => $entry->entryDate,
                'type' => $entry->type,
                'reference' => $entry->reference,
                'memo' => $entry->memo,
                'status' => 'draft',
                'source_type' => $entry->source?->getMorphClass(),
                'source_id' => $entry->source?->getKey(),
                'created_by' => Auth::id(),
            ]);

            foreach ($prepared as $line) {
                $journal->lines()->create($line);
            }

            // Flip to posted last, so the deferred balance check validates a
            // complete, balanced set at commit.
            $journal->update(['status' => 'posted', 'posted_at' => now()]);

            return $journal->load('lines');
        });
    }

    /**
     * Reverse a posted journal by posting a mirror entry. The original stays
     * posted; the two net to zero and both remain in the audit trail.
     */
    public function reverse(Journal $journal, ?string $date = null): Journal
    {
        if (! $journal->isPosted()) {
            throw new PostingException('Only posted journals can be reversed.');
        }

        $lines = $journal->lines->map(fn ($l) => new LedgerLine(
            accountId: $l->account_id,
            debit: (float) $l->credit,
            credit: (float) $l->debit,
            costCenterId: $l->cost_center_id,
            description: $l->description,
            currency: $l->currency,
            fxRate: (float) $l->fx_rate,
            partyType: $l->party_type,
            partyId: $l->party_id,
        ))->all();

        $reversal = $this->post(new LedgerEntry(
            entryDate: $date ?? now()->toDateString(),
            lines: $lines,
            type: 'reversal',
            memo: "Reversal of {$journal->number}",
            reference: $journal->number,
        ));

        $reversal->update(['reverses_id' => $journal->id]);

        return $reversal;
    }

    private function resolvePeriod(string $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();

        if (! $period) {
            throw new PostingException("No accounting period covers {$date}. Configure the fiscal calendar first.");
        }
        if ($period->status === 'locked') {
            throw new PostingException("Accounting period {$period->name} is locked.");
        }

        return $period;
    }

    private function allocateNumber(int $companyId): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $companyId, 'key' => 'journal'],
            ['prefix' => 'JV-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('journal');
    }
}
