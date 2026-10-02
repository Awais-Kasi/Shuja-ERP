<?php

namespace App\Consignment;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\ConsignmentDispatch;
use App\Models\ConsignmentSettlement;
use App\Models\NumberSequence;
use App\Models\StockLedgerEntry;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Settles a consignment: recognises revenue + COGS for quantities the agent
 * sold, and moves unsold returns back to a company warehouse — in one balanced
 * journal. Stock is sourced from the consignment warehouse and the inventory
 * relief is credited to Inventory-on-Consignment (1124), preserving the
 * balance-sheet separation. Partial and repeatable until the dispatch is drawn
 * down.
 */
class ConsignmentSettlementService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(ConsignmentSettlement $settlement): ConsignmentSettlement
    {
        if ($settlement->isPosted()) {
            throw new ConsignmentException('This settlement is already posted.');
        }

        $settlement->load('lines.item', 'dispatch.lines', 'consignmentWarehouse', 'returnWarehouse', 'agentCustomer');
        if ($settlement->lines->isEmpty()) {
            throw new ConsignmentException('Add at least one settlement line before posting.');
        }

        $dispatch = $settlement->dispatch;
        $consignmentWh = $settlement->consignmentWarehouse;
        $returnWh = $settlement->returnWarehouse ?: $dispatch->fromWarehouse;

        // The settlement must draw from this dispatch's own consignment warehouse.
        if ((int) $settlement->consignment_warehouse_id !== (int) $dispatch->to_warehouse_id) {
            throw new ConsignmentException('Settlement consignment warehouse must match the dispatch destination.');
        }

        // A reversed dispatch has had its stock pulled back to source; it cannot be settled.
        if ($dispatch->isReversed()) {
            throw new ConsignmentException('This dispatch has been reversed and cannot be settled.');
        }

        // In-transit goods have not landed on the consignment warehouse yet.
        if ($dispatch->status === 'in_transit') {
            throw new ConsignmentException('These goods are still in transit; receive them before settling.');
        }

        // Guard BEFORE any posting — aggregate per resolved dispatch line so duplicate
        // lines targeting the same dispatch line cannot collectively over-draw it.
        $requested = [];
        foreach ($settlement->lines as $line) {
            $sold = (float) $line->sold_qty;
            $returned = (float) $line->returned_qty;
            if ($sold < 0 || $returned < 0) {
                throw new ConsignmentException('Quantities cannot be negative.');
            }
            $dispatchLine = $this->dispatchLineFor($settlement, $line);
            $requested[$dispatchLine->id] = round(($requested[$dispatchLine->id] ?? 0) + $sold + $returned, 4);
        }
        foreach ($requested as $dispatchLineId => $req) {
            $dl = $dispatch->lines->firstWhere('id', $dispatchLineId);
            $remaining = (float) $dl->quantity - (float) $dl->settled_qty - (float) $dl->returned_qty;
            if ($req > $remaining + 1e-9) {
                throw new ConsignmentException("Dispatch line {$dl->id}: requested {$req} exceeds remaining {$remaining}.");
            }
        }

        return DB::transaction(function () use ($settlement, $dispatch, $consignmentWh, $returnWh) {
            // Race-safe re-check: lock the dispatch so a concurrent reversal cannot slip
            // between the guard above and this posting.
            $lockedDispatch = ConsignmentDispatch::whereKey($dispatch->id)->lockForUpdate()->first();
            if (! $lockedDispatch || $lockedDispatch->status === 'reversed') {
                throw new ConsignmentException('This dispatch has been reversed and cannot be settled.');
            }

            $date = $settlement->settlement_date->toDateString();
            $number = $this->allocateNumber();

            $revenue = [];          // income account => subtotal
            $cogs = [];             // cogs account => cost
            $consignmentCredit = []; // 1124 => sold cost + returned cost
            $returnDebit = [];      // source inventory => returned cost
            $subtotal = 0.0;
            $cogsTotal = 0.0;

            foreach ($settlement->lines as $line) {
                $item = $line->item;
                $sold = (float) $line->sold_qty;
                $returned = (float) $line->returned_qty;
                $dispatchLine = $this->dispatchLineFor($settlement, $line);

                if ($sold > 0) {
                    $out = $this->inventory->issue($item, $consignmentWh, $sold, [
                        'posting_date' => $date, 'entry_type' => 'issue', 'source' => $settlement,
                        'voucher_no' => $number, 'remarks' => 'Consignment sale',
                    ]);
                    $cost = abs((float) $out->value);
                    $amount = round($sold * (float) $line->rate, 4);
                    $line->update(['amount' => $amount, 'sold_cost' => $cost]);
                    $subtotal = round($subtotal + $amount, 4);
                    $cogsTotal = round($cogsTotal + $cost, 4);

                    $revenueAccount = $item->income_account_id ?: optional(Account::where('code', '4100')->first())->id;
                    $cogsAccount = $item->cogs_account_id ?: optional(Account::where('code', '5100')->first())->id;
                    if (! $revenueAccount || ! $cogsAccount) {
                        throw new ConsignmentException("Item {$item->code} needs revenue and COGS accounts.");
                    }
                    $revenue[$revenueAccount] = round(($revenue[$revenueAccount] ?? 0) + $amount, 4);
                    $cogs[$cogsAccount] = round(($cogs[$cogsAccount] ?? 0) + $cost, 4);
                    $cAcct = AccountResolver::accountFor($item, $consignmentWh);
                    $consignmentCredit[$cAcct] = round(($consignmentCredit[$cAcct] ?? 0) + $cost, 4);
                }

                if ($returned > 0) {
                    $out = $this->inventory->issue($item, $consignmentWh, $returned, [
                        'posting_date' => $date, 'entry_type' => 'issue', 'source' => $settlement,
                        'voucher_no' => $number, 'remarks' => 'Consignment return',
                    ]);
                    $rcost = abs((float) $out->value);
                    $this->inventory->receive($item, $returnWh, $returned, $rcost / $returned, [
                        'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $settlement,
                        'voucher_no' => $number, 'remarks' => 'Consignment return',
                    ]);
                    $line->update(['returned_cost' => $rcost]);
                    $srcAcct = AccountResolver::accountFor($item, $returnWh);
                    $returnDebit[$srcAcct] = round(($returnDebit[$srcAcct] ?? 0) + $rcost, 4);
                    $cAcct = AccountResolver::accountFor($item, $consignmentWh);
                    $consignmentCredit[$cAcct] = round(($consignmentCredit[$cAcct] ?? 0) + $rcost, 4);
                }

                $dispatchLine->update([
                    'settled_qty' => round((float) $dispatchLine->settled_qty + $sold, 4),
                    'returned_qty' => round((float) $dispatchLine->returned_qty + $returned, 4),
                ]);
            }

            $tax = round((float) $settlement->tax_amount, 4);
            $total = round($subtotal + $tax, 4);

            // Agent commission (a % of sold value, from the dispatch agreement): the agent
            // remits net of it, so their AR shrinks by the commission and we book the
            // commission as a selling expense. Zero rate → identical to the old behaviour.
            $commissionRate = (float) $dispatch->commission_rate;
            if ($commissionRate < 0 || $commissionRate > 1) {
                // Defence in depth: the create form validates 0–100%, but a bad rate from
                // any other path would silently unbalance the journal. Fail clearly instead.
                throw new ConsignmentException('Commission rate must be between 0% and 100%.');
            }
            $commission = round($subtotal * $commissionRate, 4);
            $arAmount = round($total - $commission, 4);

            $lines = [];
            if ($arAmount > 1e-9) {
                $agent = $settlement->agentCustomer;
                $ar = $agent && $agent->receivable_account_id
                    ? Account::find($agent->receivable_account_id)
                    : Account::where('control_type', 'ar')->first();
                if (! $ar) {
                    throw new ConsignmentException('No Accounts Receivable control account is configured.');
                }
                $lines[] = new LedgerLine(
                    accountId: $ar->id, debit: $arAmount, description: "Consignment settlement {$number}",
                    partyType: $agent?->getMorphClass(), partyId: $agent?->id,
                );
            }
            if ($commission > 1e-9) {
                $commissionAccount = Account::where('code', '6510')->first();
                if (! $commissionAccount) {
                    throw new ConsignmentException('No Sales Commission account (6510) is configured.');
                }
                $lines[] = LedgerLine::debit($commissionAccount->id, $commission, 'Agent commission');
            }
            foreach ($revenue as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Consignment sales');
                }
            }
            if ($tax > 1e-9) {
                $taxAccount = Account::where('control_type', 'tax')->where('type', 'liability')->first();
                if (! $taxAccount) {
                    throw new ConsignmentException('No output-tax control account is configured.');
                }
                $lines[] = LedgerLine::credit($taxAccount->id, $tax, 'Output tax');
            }
            foreach ($cogs as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Cost of goods sold');
                }
            }
            foreach ($returnDebit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Consignment returns');
                }
            }
            foreach ($consignmentCredit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Consignment inventory relieved');
                }
            }

            if (count($lines) < 2) {
                throw new ConsignmentException('Nothing to settle (no sold or returned quantities).');
            }

            // Optional return freight (a company cost, separate from the agent's balance):
            // Dr Freight & Landed Costs / Cr the funding account. Balanced on its own.
            $lines = array_merge($lines, $this->freightLines($settlement, false));

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_settlement',
                reference: $number,
                memo: $settlement->memo ?: "Consignment settlement {$number}",
                source: $settlement,
            ));
            $this->inventory->stampJournal($settlement, $journal->id);

            $settlement->update([
                'number' => $number,
                'status' => 'posted',
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'commission_rate' => $commissionRate,
                'commission_amount' => $commission,
                'cogs_total' => $cogsTotal,
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            $this->syncDispatchStatus($dispatch);

            return $settlement;
        });
    }

    /**
     * Reverse a posted settlement (LIFO — only the latest on the dispatch). Un-recognises
     * the sale (AR/revenue/tax/commission/COGS) and puts stock back: sold units return to
     * the consignment warehouse at their original cost; returned units are pulled from the
     * return warehouse (at current valuation) back onto consignment. Dispatch draw-down is
     * restored. Balances by construction; the consignment stock == GL 1124 invariant holds.
     */
    public function reverse(ConsignmentSettlement $settlement): ConsignmentSettlement
    {
        if ($settlement->isReversed()) {
            throw new ConsignmentException('This settlement is already reversed.');
        }
        if (! $settlement->isPosted()) {
            throw new ConsignmentException('Only a posted settlement can be reversed.');
        }

        $settlement->load('lines.item', 'dispatch.lines', 'consignmentWarehouse', 'returnWarehouse', 'agentCustomer');
        $dispatch = $settlement->dispatch;
        $consignmentWh = $settlement->consignmentWarehouse;
        $returnWh = $settlement->returnWarehouse ?: $dispatch->fromWarehouse;

        // A settlement that recognised real COGS but has no per-line cost snapshot (posted
        // before cost capture existed) cannot be reversed automatically — doing so would
        // restore stock at zero value and leave COGS/1124 un-reversed. Refuse rather than
        // corrupt. (A genuinely zero-cost sale has cogs_total 0 and reverses fine.)
        if ((float) $settlement->cogs_total > 1e-9 && (float) $settlement->lines->sum('sold_cost') <= 1e-9) {
            throw new ConsignmentException('This settlement predates per-line cost capture and cannot be reversed automatically.');
        }

        // LIFO: a later posted settlement on the same dispatch must be reversed first.
        $laterExists = ConsignmentSettlement::where('consignment_dispatch_id', $dispatch->id)
            ->where('status', 'posted')
            ->where('id', '>', $settlement->id)
            ->exists();
        if ($laterExists) {
            throw new ConsignmentException('A later settlement exists on this dispatch; reverse it first.');
        }

        return DB::transaction(function () use ($settlement, $dispatch, $consignmentWh, $returnWh) {
            // Lock the settlement AND the parent dispatch (the latter is what post()/dispatch
            // reversal lock) so a concurrent settlement can't violate LIFO or race the
            // dispatch-line quantity restore.
            $locked = ConsignmentSettlement::whereKey($settlement->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new ConsignmentException('This settlement can no longer be reversed.');
            }
            ConsignmentDispatch::whereKey($dispatch->id)->lockForUpdate()->first();
            $laterUnderLock = ConsignmentSettlement::where('consignment_dispatch_id', $dispatch->id)
                ->where('status', 'posted')->where('id', '>', $settlement->id)->exists();
            if ($laterUnderLock) {
                throw new ConsignmentException('A later settlement exists on this dispatch; reverse it first.');
            }

            $date = now()->toDateString();
            $sleIds = [];
            $revenueDebit = [];      // income account => subtotal (Dr: un-recognise revenue)
            $cogsCredit = [];        // cogs account => sold cost (Cr: un-recognise COGS)
            $consignmentDebit = [];  // 1124 => value put back on consignment
            $returnCredit = [];      // return warehouse inventory => value relieved

            foreach ($settlement->lines as $line) {
                $item = $line->item;
                $sold = (float) $line->sold_qty;
                $returned = (float) $line->returned_qty;
                $soldCost = round((float) $line->sold_cost, 4);

                if ($sold > 0) {
                    // Sold units go back onto consignment at their original cost.
                    $in = $this->inventory->receive($item, $consignmentWh, $sold, $soldCost / $sold, [
                        'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $settlement,
                        'voucher_no' => $settlement->number, 'remarks' => 'Consignment settlement reversal',
                    ]);
                    $sleIds[] = $in->id;

                    $revenueAccount = $item->income_account_id ?: optional(Account::where('code', '4100')->first())->id;
                    $cogsAccount = $item->cogs_account_id ?: optional(Account::where('code', '5100')->first())->id;
                    if (! $revenueAccount || ! $cogsAccount) {
                        throw new ConsignmentException("Item {$item->code} needs revenue and COGS accounts.");
                    }
                    $revenueDebit[$revenueAccount] = round(($revenueDebit[$revenueAccount] ?? 0) + round($sold * (float) $line->rate, 4), 4);
                    $cogsCredit[$cogsAccount] = round(($cogsCredit[$cogsAccount] ?? 0) + $soldCost, 4);
                    $cAcct = AccountResolver::accountFor($item, $consignmentWh);
                    $consignmentDebit[$cAcct] = round(($consignmentDebit[$cAcct] ?? 0) + $soldCost, 4);
                }

                if ($returned > 0) {
                    // Returned units come back out of the return warehouse (current valuation)
                    // and go back onto consignment — keeping both warehouses' stock == GL.
                    $out = $this->inventory->issue($item, $returnWh, $returned, [
                        'posting_date' => $date, 'entry_type' => 'issue', 'source' => $settlement,
                        'voucher_no' => $settlement->number, 'remarks' => 'Consignment settlement reversal',
                    ]);
                    $sleIds[] = $out->id;
                    $rcost = abs((float) $out->value);
                    $in = $this->inventory->receive($item, $consignmentWh, $returned, $rcost / $returned, [
                        'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $settlement,
                        'voucher_no' => $settlement->number, 'remarks' => 'Consignment settlement reversal',
                    ]);
                    $sleIds[] = $in->id;

                    $srcAcct = AccountResolver::accountFor($item, $returnWh);
                    $returnCredit[$srcAcct] = round(($returnCredit[$srcAcct] ?? 0) + $rcost, 4);
                    $cAcct = AccountResolver::accountFor($item, $consignmentWh);
                    $consignmentDebit[$cAcct] = round(($consignmentDebit[$cAcct] ?? 0) + $rcost, 4);
                }

                $dispatchLine = $this->dispatchLineFor($settlement, $line);
                $dispatchLine->update([
                    'settled_qty' => round((float) $dispatchLine->settled_qty - $sold, 4),
                    'returned_qty' => round((float) $dispatchLine->returned_qty - $returned, 4),
                ]);
            }

            $subtotal = round((float) $settlement->subtotal, 4);
            $tax = round((float) $settlement->tax_amount, 4);
            $commission = round((float) $settlement->commission_amount, 4);
            $arAmount = round($subtotal + $tax - $commission, 4);

            $lines = [];
            foreach ($consignmentDebit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Consignment stock restored');
                }
            }
            foreach ($cogsCredit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'COGS reversed');
                }
            }
            foreach ($returnCredit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Consignment return reversed');
                }
            }
            foreach ($revenueDebit as $accountId => $amount) {
                if ($amount > 1e-9) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Consignment sales reversed');
                }
            }
            if ($tax > 1e-9) {
                $taxAccount = Account::where('control_type', 'tax')->where('type', 'liability')->first();
                if (! $taxAccount) {
                    throw new ConsignmentException('No output-tax control account is configured.');
                }
                $lines[] = LedgerLine::debit($taxAccount->id, $tax, 'Output tax reversed');
            }
            if ($commission > 1e-9) {
                $commissionAccount = Account::where('code', '6510')->first();
                if (! $commissionAccount) {
                    throw new ConsignmentException('No Sales Commission account (6510) is configured.');
                }
                $lines[] = LedgerLine::credit($commissionAccount->id, $commission, 'Agent commission reversed');
            }
            if ($arAmount > 1e-9) {
                $agent = $settlement->agentCustomer;
                $ar = $agent && $agent->receivable_account_id
                    ? Account::find($agent->receivable_account_id)
                    : Account::where('control_type', 'ar')->first();
                if (! $ar) {
                    throw new ConsignmentException('No Accounts Receivable control account is configured.');
                }
                $lines[] = new LedgerLine(
                    accountId: $ar->id, credit: $arAmount, description: "Settlement {$settlement->number} reversed",
                    partyType: $agent?->getMorphClass(), partyId: $agent?->id,
                );
            }

            if (count($lines) < 2) {
                throw new ConsignmentException('This settlement has nothing to reverse.');
            }

            // Mirror the return-freight posting so the reversal nets it to zero.
            $lines = array_merge($lines, $this->freightLines($settlement, true));

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_settlement_reversal',
                reference: $settlement->number,
                memo: "Reversal of consignment settlement {$settlement->number}",
                source: $settlement,
            ));

            // Stamp only the reversal's own stock entries (see the dispatch-reversal note).
            StockLedgerEntry::whereIn('id', $sleIds)->update(['journal_id' => $journal->id]);

            $settlement->update([
                'status' => 'reversed',
                'reversal_journal_id' => $journal->id,
                'reversed_at' => now(),
            ]);

            $this->syncDispatchStatus($dispatch);

            return $settlement->load('journal', 'reversalJournal', 'lines.item');
        });
    }

    /**
     * The optional return-freight leg (Dr Freight & Landed Costs / Cr funding), or its
     * mirror on reversal. Empty when no freight was recorded.
     *
     * @return array<int, LedgerLine>
     */
    private function freightLines(ConsignmentSettlement $settlement, bool $reverse): array
    {
        $freight = round((float) $settlement->return_freight, 4);
        if ($freight <= 1e-9) {
            return [];
        }

        $funding = $settlement->return_freight_account_id ? Account::find($settlement->return_freight_account_id) : null;
        if (! $funding || ! $funding->isPostable()) {
            throw new ConsignmentException('Choose a valid account to fund the return freight.');
        }
        $freightAccount = Account::where('code', '5400')->first();
        if (! $freightAccount || ! $freightAccount->isPostable()) {
            throw new ConsignmentException('No Freight & Landed Costs account (5400) is configured.');
        }

        return $reverse
            ? [LedgerLine::debit($funding->id, $freight, 'Return freight reversed'), LedgerLine::credit($freightAccount->id, $freight, 'Return freight reversed')]
            : [LedgerLine::debit($freightAccount->id, $freight, 'Consignment return freight'), LedgerLine::credit($funding->id, $freight, 'Consignment return freight')];
    }

    private function dispatchLineFor(ConsignmentSettlement $settlement, $line)
    {
        if ($line->consignment_dispatch_line_id) {
            $dl = $settlement->dispatch->lines->firstWhere('id', $line->consignment_dispatch_line_id);
            if ($dl) {
                return $dl;
            }
        }
        $dl = $settlement->dispatch->lines->firstWhere('item_id', $line->item_id);
        if (! $dl) {
            throw new ConsignmentException("Item {$line->item->code} is not part of dispatch {$settlement->dispatch->number}.");
        }

        return $dl;
    }

    private function syncDispatchStatus($dispatch): void
    {
        // Never re-derive the status of a dispatch that has itself been reversed.
        if ($dispatch->status === 'reversed') {
            return;
        }
        $dispatch->load('lines');
        $fullyDrawn = $dispatch->lines->every(fn ($l) => (float) $l->settled_qty + (float) $l->returned_qty >= (float) $l->quantity - 1e-9);
        $anyMovement = $dispatch->lines->contains(fn ($l) => (float) $l->settled_qty + (float) $l->returned_qty > 1e-9);
        // No movement (e.g. after reversing the only settlement) returns it to 'posted'.
        $dispatch->update(['status' => $fullyDrawn ? 'settled' : ($anyMovement ? 'partially_settled' : 'posted')]);
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'consignment_settlement'],
            ['prefix' => 'CST-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('consignment_settlement');
    }
}
