<?php

namespace App\Consignment;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\ConsignmentDispatch;
use App\Models\NumberSequence;
use App\Models\StockLedgerEntry;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Ships owned goods to a consignment warehouse. Stock moves via the
 * issue()+receive() loop; because the two locations resolve to different GL
 * accounts, one reclass journal is posted: Dr Inventory-on-Consignment (1124) /
 * Cr the source inventory account. No sale is recognised — the goods are still
 * owned by the company.
 */
class ConsignmentDispatchService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(ConsignmentDispatch $dispatch): ConsignmentDispatch
    {
        if ($dispatch->isPosted()) {
            throw new ConsignmentException('This dispatch is already posted.');
        }

        $dispatch->load('lines.item', 'fromWarehouse', 'toWarehouse');

        if ($dispatch->lines->isEmpty()) {
            throw new ConsignmentException('Add at least one line before posting.');
        }
        if ($dispatch->from_warehouse_id === $dispatch->to_warehouse_id) {
            throw new ConsignmentException('Source and consignment warehouses must differ.');
        }
        if ($dispatch->toWarehouse->type !== 'consignment') {
            throw new ConsignmentException('The destination must be a consignment-type warehouse.');
        }

        // Optional two-step shipping: issue into Goods-in-Transit now, land on consignment
        // at receipt. Leaves the dispatch 'in_transit' (not settleable until received).
        if ($dispatch->via_transit) {
            return $this->postToTransit($dispatch);
        }

        return DB::transaction(function () use ($dispatch) {
            $date = $dispatch->dispatch_date->toDateString();
            $number = $this->allocateNumber();

            $debit = [];  // consignment account => total
            $credit = []; // source account => total

            foreach ($dispatch->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    continue;
                }

                $out = $this->inventory->issue($item, $dispatch->fromWarehouse, $qty, [
                    'posting_date' => $date, 'entry_type' => 'issue', 'source' => $dispatch,
                    'voucher_no' => $number, 'remarks' => 'Consignment dispatch',
                ]);
                $cost = abs((float) $out->value);
                $rate = $cost / $qty;

                $this->inventory->receive($item, $dispatch->toWarehouse, $qty, $rate, [
                    'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $dispatch,
                    'voucher_no' => $number, 'remarks' => 'Consignment dispatch',
                ]);

                $line->update(['dispatch_rate' => round($rate, 4), 'dispatch_value' => round($cost, 4)]);

                $toAccount = AccountResolver::accountFor($item, $dispatch->toWarehouse);
                $fromAccount = AccountResolver::accountFor($item, $dispatch->fromWarehouse);
                $debit[$toAccount] = round(($debit[$toAccount] ?? 0) + $cost, 4);
                $credit[$fromAccount] = round(($credit[$fromAccount] ?? 0) + $cost, 4);
            }

            $lines = [];
            foreach ($debit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Goods on consignment');
                }
            }
            foreach ($credit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Stock dispatched to consignment');
                }
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_dispatch',
                reference: $number,
                memo: $dispatch->memo ?: "Consignment dispatch {$number}",
                source: $dispatch,
            ));
            $this->inventory->stampJournal($dispatch, $journal->id);

            $dispatch->update([
                'number' => $number,
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            return $dispatch;
        });
    }

    /**
     * Reverse a posted, untouched dispatch: move the goods back from the consignment
     * warehouse to the source and post the mirror reclass (Dr source inventory / Cr
     * Inventory-on-Consignment). Only a dispatch with no settlements and no capitalised
     * expenses can be reversed — otherwise its consignment stock/value has been drawn
     * down or altered and those documents must be unwound first.
     */
    public function reverse(ConsignmentDispatch $dispatch): ConsignmentDispatch
    {
        if ($dispatch->status === 'draft') {
            throw new ConsignmentException('A draft dispatch has nothing to reverse.');
        }
        if ($dispatch->isReversed()) {
            throw new ConsignmentException('This dispatch is already reversed.');
        }
        // An in-transit dispatch is reversed by returning the goods from transit to source.
        if ($dispatch->status === 'in_transit') {
            return $this->reverseInTransit($dispatch);
        }
        if ($dispatch->status !== 'posted') {
            throw new ConsignmentException('This dispatch has been settled; reverse the settlements before reversing the dispatch.');
        }
        if ($dispatch->settlements()->exists()) {
            throw new ConsignmentException('This dispatch has settlements and cannot be reversed.');
        }
        if ($dispatch->expenses()->exists()) {
            throw new ConsignmentException('This dispatch has capitalised expenses; those must be reversed before the dispatch (not yet supported).');
        }

        $dispatch->load('lines.item', 'fromWarehouse', 'toWarehouse');

        return DB::transaction(function () use ($dispatch) {
            // Serialise concurrent reversals against the locked row, and re-run the
            // settlement/expense guards under the lock — an expense post never changes the
            // dispatch status, so a status-only re-check could miss one that committed
            // between the pre-transaction guard and here.
            $locked = ConsignmentDispatch::whereKey($dispatch->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new ConsignmentException('This dispatch can no longer be reversed.');
            }
            if ($dispatch->settlements()->exists() || $dispatch->expenses()->exists()) {
                throw new ConsignmentException('This dispatch now has settlements or expenses and can no longer be reversed.');
            }

            $date = now()->toDateString();
            $sleIds = [];
            $debit = [];  // source inventory account => total
            $credit = []; // consignment (1124) account => total

            foreach ($dispatch->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    continue;
                }

                $out = $this->inventory->issue($item, $dispatch->toWarehouse, $qty, [
                    'posting_date' => $date, 'entry_type' => 'issue', 'source' => $dispatch,
                    'voucher_no' => $dispatch->number, 'remarks' => 'Consignment dispatch reversal',
                ]);
                $sleIds[] = $out->id;
                $cost = abs((float) $out->value);
                $rate = $cost / $qty;

                $in = $this->inventory->receive($item, $dispatch->fromWarehouse, $qty, $rate, [
                    'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $dispatch,
                    'voucher_no' => $dispatch->number, 'remarks' => 'Consignment dispatch reversal',
                ]);
                $sleIds[] = $in->id;

                $fromAccount = AccountResolver::accountFor($item, $dispatch->fromWarehouse);
                $toAccount = AccountResolver::accountFor($item, $dispatch->toWarehouse);
                $debit[$fromAccount] = round(($debit[$fromAccount] ?? 0) + $cost, 4);
                $credit[$toAccount] = round(($credit[$toAccount] ?? 0) + $cost, 4);
            }

            $lines = [];
            foreach ($debit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Consignment dispatch reversed');
                }
            }
            foreach ($credit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Consignment stock returned to source');
                }
            }
            if (count($lines) < 2) {
                throw new ConsignmentException('This dispatch has no stock to reverse.');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_dispatch_reversal',
                reference: $dispatch->number,
                memo: "Reversal of consignment dispatch {$dispatch->number}",
                source: $dispatch,
            ));

            // Stamp ONLY the reversal's own stock entries — stampJournal() would re-point
            // the original dispatch entries too, since it matches by source.
            StockLedgerEntry::whereIn('id', $sleIds)->update(['journal_id' => $journal->id]);

            $dispatch->update([
                'status' => 'reversed',
                'reversal_journal_id' => $journal->id,
                'reversed_at' => now(),
            ]);

            return $dispatch->load('journal', 'reversalJournal', 'lines.item');
        });
    }

    /**
     * Post a via-transit dispatch: issue stock out of the source into Goods-in-Transit
     * (Dr 1140 / Cr source inventory). Stock is not on consignment yet — receive() lands it.
     */
    private function postToTransit(ConsignmentDispatch $dispatch): ConsignmentDispatch
    {
        $transit = $this->transitAccount();

        return DB::transaction(function () use ($dispatch, $transit) {
            $date = $dispatch->dispatch_date->toDateString();
            $number = $this->allocateNumber();

            $credit = []; // source account => total
            $transitDebit = 0.0;

            foreach ($dispatch->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    continue;
                }

                $out = $this->inventory->issue($item, $dispatch->fromWarehouse, $qty, [
                    'posting_date' => $date, 'entry_type' => 'issue', 'source' => $dispatch,
                    'voucher_no' => $number, 'remarks' => 'Consignment dispatch (to transit)',
                ]);
                $cost = abs((float) $out->value);
                $line->update(['dispatch_rate' => round($cost / $qty, 4), 'dispatch_value' => round($cost, 4)]);

                $fromAccount = AccountResolver::accountFor($item, $dispatch->fromWarehouse);
                $credit[$fromAccount] = round(($credit[$fromAccount] ?? 0) + $cost, 4);
                $transitDebit = round($transitDebit + $cost, 4);
            }

            $lines = [];
            if ($transitDebit > 1e-9) {
                $lines[] = LedgerLine::debit($transit->id, $transitDebit, 'Goods in transit to consignment');
            }
            foreach ($credit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Stock dispatched (in transit)');
                }
            }
            if (count($lines) < 2) {
                throw new ConsignmentException('This dispatch has no stock to send.');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_dispatch',
                reference: $number,
                memo: $dispatch->memo ?: "Consignment dispatch {$number}",
                source: $dispatch,
            ));
            $this->inventory->stampJournal($dispatch, $journal->id);

            $dispatch->update([
                'number' => $number,
                'status' => 'in_transit',
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            return $dispatch;
        });
    }

    /**
     * Receive an in-transit dispatch onto the consignment warehouse: land the stock and
     * post Dr Inventory-on-Consignment (1124) / Cr Goods-in-Transit (1140). After this the
     * dispatch is 'posted' and behaves exactly like a directly-shipped one.
     */
    public function receive(ConsignmentDispatch $dispatch): ConsignmentDispatch
    {
        if ($dispatch->status !== 'in_transit') {
            throw new ConsignmentException('Only an in-transit dispatch can be received.');
        }

        $dispatch->load('lines.item', 'toWarehouse');
        $transit = $this->transitAccount();

        return DB::transaction(function () use ($dispatch, $transit) {
            $locked = ConsignmentDispatch::whereKey($dispatch->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'in_transit') {
                throw new ConsignmentException('This dispatch is no longer in transit.');
            }

            $date = now()->toDateString();
            $sleIds = [];
            $debit = []; // consignment (1124) => total
            $transitCredit = 0.0;

            foreach ($dispatch->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    continue;
                }

                $cost = round((float) $line->dispatch_value, 4);
                $in = $this->inventory->receive($item, $dispatch->toWarehouse, $qty, $cost / $qty, [
                    'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $dispatch,
                    'voucher_no' => $dispatch->number, 'remarks' => 'Consignment receipt',
                ]);
                $sleIds[] = $in->id;

                $toAccount = AccountResolver::accountFor($item, $dispatch->toWarehouse);
                $debit[$toAccount] = round(($debit[$toAccount] ?? 0) + $cost, 4);
                $transitCredit = round($transitCredit + $cost, 4);
            }

            $lines = [];
            foreach ($debit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Goods received on consignment');
                }
            }
            if ($transitCredit > 1e-9) {
                $lines[] = LedgerLine::credit($transit->id, $transitCredit, 'Goods in transit received');
            }
            if (count($lines) < 2) {
                throw new ConsignmentException('Nothing to receive.');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_receipt',
                reference: $dispatch->number,
                memo: "Consignment receipt {$dispatch->number}",
                source: $dispatch,
            ));
            StockLedgerEntry::whereIn('id', $sleIds)->update(['journal_id' => $journal->id]);

            $dispatch->update(['status' => 'posted', 'received_at' => now()]);

            return $dispatch->load('journal', 'lines.item');
        });
    }

    /**
     * Reverse an in-transit dispatch: return the goods from transit back to source
     * (Dr source inventory / Cr Goods-in-Transit) and mark it reversed.
     */
    private function reverseInTransit(ConsignmentDispatch $dispatch): ConsignmentDispatch
    {
        $dispatch->load('lines.item', 'fromWarehouse');
        $transit = $this->transitAccount();

        return DB::transaction(function () use ($dispatch, $transit) {
            $locked = ConsignmentDispatch::whereKey($dispatch->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'in_transit') {
                throw new ConsignmentException('This dispatch can no longer be reversed.');
            }

            $date = now()->toDateString();
            $sleIds = [];
            $debit = []; // source inventory => total
            $transitCredit = 0.0;

            foreach ($dispatch->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    continue;
                }

                $cost = round((float) $line->dispatch_value, 4);
                $in = $this->inventory->receive($item, $dispatch->fromWarehouse, $qty, $cost / $qty, [
                    'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $dispatch,
                    'voucher_no' => $dispatch->number, 'remarks' => 'Consignment transit reversal',
                ]);
                $sleIds[] = $in->id;

                $fromAccount = AccountResolver::accountFor($item, $dispatch->fromWarehouse);
                $debit[$fromAccount] = round(($debit[$fromAccount] ?? 0) + $cost, 4);
                $transitCredit = round($transitCredit + $cost, 4);
            }

            $lines = [];
            foreach ($debit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Transit stock returned to source');
                }
            }
            if ($transitCredit > 1e-9) {
                $lines[] = LedgerLine::credit($transit->id, $transitCredit, 'Goods in transit reversed');
            }
            if (count($lines) < 2) {
                throw new ConsignmentException('This dispatch has no stock to reverse.');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_dispatch_reversal',
                reference: $dispatch->number,
                memo: "Reversal of consignment dispatch {$dispatch->number}",
                source: $dispatch,
            ));
            StockLedgerEntry::whereIn('id', $sleIds)->update(['journal_id' => $journal->id]);

            $dispatch->update([
                'status' => 'reversed',
                'reversal_journal_id' => $journal->id,
                'reversed_at' => now(),
            ]);

            return $dispatch->load('journal', 'reversalJournal', 'lines.item');
        });
    }

    private function transitAccount(): Account
    {
        $transit = Account::where('code', '1140')->first();
        if (! $transit || ! $transit->isPostable()) {
            throw new ConsignmentException('No Goods in Transit account (1140) is configured.');
        }

        return $transit;
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'consignment_dispatch'],
            ['prefix' => 'CDO-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('consignment_dispatch');
    }
}
