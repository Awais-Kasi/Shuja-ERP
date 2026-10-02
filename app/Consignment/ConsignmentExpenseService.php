<?php

namespace App\Consignment;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\ConsignmentDispatch;
use App\Models\ConsignmentExpense;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Captures landed costs (freight, transport, loading, labour) against a
 * dispatch. Capitalised lines are allocated across the dispatch's on-hand items
 * (by value or quantity, residual forced onto the largest so shares sum exactly)
 * and applied via InventoryService::addLandedCost — Dr Inventory-on-Consignment
 * / Cr cash|bank|accrued|AP. Non-capitalised lines go straight to P&L.
 */
class ConsignmentExpenseService
{
    private const DEFAULT_PL = ['freight' => '5400', 'transport' => '6400', 'loading' => '6500', 'labour' => '6500', 'other' => '6900'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(ConsignmentExpense $expense): ConsignmentExpense
    {
        if ($expense->isPosted()) {
            throw new ConsignmentException('This expense is already posted.');
        }

        $expense->load('lines', 'dispatch.lines.item', 'warehouse');
        if ($expense->lines->isEmpty()) {
            throw new ConsignmentException('Add at least one expense line before posting.');
        }
        if ((int) $expense->warehouse_id !== (int) $expense->dispatch->to_warehouse_id) {
            throw new ConsignmentException('The expense warehouse must be the dispatch consignment warehouse.');
        }
        if ($expense->dispatch->isReversed()) {
            throw new ConsignmentException('This dispatch has been reversed; no further expenses can be capitalised against it.');
        }
        if ($expense->dispatch->status === 'in_transit') {
            throw new ConsignmentException('These goods are still in transit; receive them before capitalising expenses.');
        }

        $credit = Account::find($expense->credit_account_id);
        if (! $credit) {
            throw new ConsignmentException('Choose a valid credit account.');
        }

        return DB::transaction(function () use ($expense, $credit) {
            // Lock the parent dispatch so an expense and a reversal serialise on the same
            // row; re-check the state under the lock.
            $lockedDispatch = ConsignmentDispatch::whereKey($expense->consignment_dispatch_id)->lockForUpdate()->first();
            if (! $lockedDispatch || $lockedDispatch->status === 'reversed') {
                throw new ConsignmentException('This dispatch has been reversed; no further expenses can be capitalised against it.');
            }

            $date = $expense->expense_date->toDateString();
            $number = $this->allocateNumber();

            $ecap = round((float) $expense->lines->where('capitalise', true)->sum('amount'), 4);
            $lines = [];
            $applied = 0.0; // sum actually capitalised onto stock — the GL debit derives from THIS, never ecap alone

            if ($ecap > 1e-9) {
                $capAccount = AccountResolver::accountFor($expense->dispatch->lines->first()->item, $expense->warehouse);
                ['shares' => $shares, 'items' => $items] = $this->allocate($expense, $ecap);
                foreach ($shares as $itemId => $share) {
                    if ($share > 1e-9) {
                        $this->inventory->addLandedCost($items[$itemId], $expense->warehouse, $share, [
                            'posting_date' => $date, 'source' => $expense, 'voucher_no' => $number, 'remarks' => 'Landed cost',
                        ]);
                        $applied = round($applied + $share, 4);
                    }
                }
                if ($applied > 1e-9) {
                    // Debit exactly what was added to stock, keeping the reconciliation invariant intact.
                    $lines[] = LedgerLine::debit($capAccount, $applied, 'Landed cost capitalised');
                }
            }

            // Non-capitalised legs → straight to P&L
            $nonCapTotal = 0.0;
            foreach ($expense->lines->where('capitalise', false) as $line) {
                $amount = round((float) $line->amount, 4);
                if ($amount <= 1e-9) {
                    continue;
                }
                $accountId = $line->expense_account_id
                    ?? Account::where('code', self::DEFAULT_PL[$line->expense_type] ?? '6900')->value('id');
                if (! $accountId) {
                    throw new ConsignmentException("No P&L account for expense type {$line->expense_type}.");
                }
                $lines[] = LedgerLine::debit((int) $accountId, $amount, ucfirst($line->expense_type));
                $nonCapTotal = round($nonCapTotal + $amount, 4);
            }

            $total = round($applied + $nonCapTotal, 4); // credit == sum of debits actually emitted
            $lines[] = new LedgerLine(
                accountId: $credit->id,
                credit: $total,
                description: "Consignment expense {$number}",
                partyType: $expense->party_type,
                partyId: $expense->party_id,
            );

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_expense',
                reference: $number,
                memo: $expense->memo ?: "Consignment expense {$number}",
                source: $expense,
            ));
            $this->inventory->stampJournal($expense, $journal->id);

            $expense->update([
                'number' => $number,
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            return $expense;
        });
    }

    /**
     * Reverse a posted expense: remove the capitalised value from stock (using the exact
     * per-item landed-cost entries it recorded) and post the mirror journal. Only allowed
     * while the dispatch stock is intact — a settlement would have flushed part of the
     * capitalised cost to COGS, so those must be reversed first.
     */
    public function reverse(ConsignmentExpense $expense): ConsignmentExpense
    {
        if ($expense->isReversed()) {
            throw new ConsignmentException('This expense is already reversed.');
        }
        if (! $expense->isPosted()) {
            throw new ConsignmentException('Only a posted expense can be reversed.');
        }
        if (! $expense->journal_id) {
            throw new ConsignmentException('This expense has no journal to reverse.');
        }

        $expense->load('dispatch');
        if ($expense->dispatch->isReversed()) {
            throw new ConsignmentException('The dispatch has been reversed.');
        }
        if ($expense->dispatch->settlements()->where('status', 'posted')->exists()) {
            throw new ConsignmentException('This dispatch has settlements; reverse them before reversing the expense.');
        }

        return DB::transaction(function () use ($expense) {
            $locked = ConsignmentExpense::whereKey($expense->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new ConsignmentException('This expense can no longer be reversed.');
            }

            $date = now()->toDateString();

            // Pull the capitalised value back out of stock, item by item, from the exact
            // landed-cost entries this expense wrote.
            $capEntries = StockLedgerEntry::where('source_type', $expense->getMorphClass())
                ->where('source_id', $expense->id)
                ->where('entry_type', 'landed_cost')
                ->get();

            foreach ($capEntries as $sle) {
                $amount = round((float) $sle->value, 4);
                if ($amount <= 1e-9) {
                    continue;
                }
                $item = Item::find($sle->item_id);
                $warehouse = Warehouse::find($sle->warehouse_id);
                if ($item && $warehouse) {
                    $this->inventory->removeLandedCost($item, $warehouse, $amount, [
                        'posting_date' => $date, 'source' => $expense,
                        'voucher_no' => $expense->number, 'remarks' => 'Landed cost reversal',
                    ]);
                }
            }

            // Mirror the whole expense journal (capitalised + P&L legs + credit account).
            $reversal = $this->posting->reverse($expense->journal, $date);

            $expense->update([
                'status' => 'reversed',
                'reversal_journal_id' => $reversal->id,
                'reversed_at' => now(),
            ]);

            return $expense->load('journal', 'reversalJournal');
        });
    }

    /**
     * Allocate the capitalised total across the dispatch's on-hand items.
     *
     * @return array{shares: array<int, float>, items: array<int, \App\Models\Item>}
     */
    private function allocate(ConsignmentExpense $expense, float $ecap): array
    {
        $basis = $expense->allocation_basis === 'quantity' ? 'quantity' : 'value';
        $weights = [];
        $items = [];

        foreach ($expense->dispatch->lines as $line) {
            $itemId = $line->item_id;
            if (isset($weights[$itemId])) {
                continue;
            }
            $balance = StockBalance::where('item_id', $itemId)->where('warehouse_id', $expense->warehouse_id)->first();
            $weight = $basis === 'quantity' ? (float) ($balance->quantity ?? 0) : (float) ($balance->value ?? 0);
            if ($weight > 0) {
                $weights[$itemId] = $weight;
                $items[$itemId] = $line->item;
            }
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            throw new ConsignmentException('No on-hand stock at the consignment warehouse to capitalise landed cost onto.');
        }

        $shares = [];
        foreach ($weights as $itemId => $weight) {
            $shares[$itemId] = round($ecap * $weight / $totalWeight, 4);
        }
        // Force the rounding residual onto the largest-weight line so shares sum exactly to ecap.
        $largestId = array_keys($weights, max($weights))[0];
        $shares[$largestId] = round($shares[$largestId] + ($ecap - array_sum($shares)), 4);

        return ['shares' => $shares, 'items' => $items];
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'consignment_expense'],
            ['prefix' => 'CEX-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('consignment_expense');
    }
}
