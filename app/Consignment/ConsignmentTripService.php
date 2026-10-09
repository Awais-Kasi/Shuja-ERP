<?php

namespace App\Consignment;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\ConsignmentTrip;
use App\Models\ConsignmentTripStep;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Runs a Consignment Trip end-to-end: a batch of goods travelling under a truck
 * number, picking up costs at every leg, then sold.
 *
 * Accounting = LANDED COSTING. Every leg's cost is capitalised onto the goods via
 * InventoryService::addLandedCost (Dr Inventory / Cr cash|bank|payable), so the
 * stock value grows as the batch moves. The journey steps are identical whether
 * the goods were PURCHASED (the trip books the receipt: Dr Inventory / Cr Payable)
 * or MANUFACTURED (already in stock from a work order, so the trip only adds leg
 * costs). On settlement the goods are issued at their landed weighted-average cost
 * and sold:
 *     Dr Receivable/Bank   (sale)      Cr Sales Revenue (sale)
 *     Dr COGS (landed cost)            Cr Inventory     (landed cost)
 * Batch Profit/Loss = Sale - landed cost.
 */
class ConsignmentTripService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    /**
     * Create a draft trip with its journey steps. No GL or stock movement yet.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ConsignmentTrip
    {
        $quantity = round((float) $data['quantity'], 4);
        $packages = (int) ($data['packages'] ?? 0);
        $goodsRate = round((float) ($data['goods_rate'] ?? 0), 4);
        $goodsCost = round($quantity * $goodsRate, 4);

        return DB::transaction(function () use ($data, $quantity, $packages, $goodsRate, $goodsCost) {
            $trip = ConsignmentTrip::create([
                'number' => $this->allocateNumber(),
                'vehicle_no' => $data['vehicle_no'] ?? null,
                'source' => $data['source'] ?? 'purchase',
                'item_id' => $data['item_id'],
                'warehouse_id' => $data['warehouse_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'origin' => $data['origin'] ?? null,
                'destination' => $data['destination'] ?? null,
                'quantity' => $quantity,
                'packages' => $packages,
                'goods_rate' => $goodsRate,
                'goods_cost' => $goodsCost,
                'goods_credit_account_id' => $data['goods_credit_account_id'] ?? null,
                'sale_account_id' => $data['sale_account_id'] ?? null,
                'status' => 'draft',
                'trip_date' => $data['trip_date'],
                'memo' => $data['memo'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $logistics = 0.0;
            foreach (array_values($data['steps'] ?? []) as $i => $step) {
                $basis = $step['basis'] ?? 'flat';
                $rate = round((float) ($step['rate'] ?? 0), 4);
                $amount = ConsignmentTripStep::computeAmount($basis, $rate, $quantity, $packages);
                $trip->steps()->create([
                    'company_id' => $trip->company_id,
                    'sequence' => $i + 1,
                    'type' => $step['type'] ?? 'other',
                    'label' => $step['label'] ?? null,
                    'location' => $step['location'] ?? null,
                    'vehicle_no' => $step['vehicle_no'] ?? $trip->vehicle_no,
                    'basis' => $basis,
                    'rate' => $rate,
                    'amount' => $amount,
                    'credit_account_id' => $step['credit_account_id'] ?? null,
                    'status' => 'pending',
                ]);
                $logistics = round($logistics + $amount, 4);
            }

            $trip->update([
                'logistics_cost' => $logistics,
                'total_cost' => round($goodsCost + $logistics, 4),
            ]);

            return $trip->load('steps');
        });
    }

    /**
     * Capitalise the goods (if purchased) and every leg cost onto stock. This is
     * the landed-costing step: after it, the batch sits in inventory at its full
     * journey cost, ready to sell.
     */
    public function post(ConsignmentTrip $trip): ConsignmentTrip
    {
        if (! $trip->isDraft()) {
            throw new ConsignmentException('Only a draft trip can be posted.');
        }

        $trip->load('steps', 'item', 'warehouse', 'supplier');
        $item = $trip->item;
        $warehouse = $trip->warehouse;

        if (! $item || ! $warehouse) {
            throw new ConsignmentException('The trip needs a valid item and warehouse.');
        }
        if ((float) $trip->quantity <= 0) {
            throw new ConsignmentException('The trip quantity must be positive.');
        }

        return DB::transaction(function () use ($trip, $item, $warehouse) {
            $locked = ConsignmentTrip::whereKey($trip->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'draft') {
                throw new ConsignmentException('This trip is no longer a draft.');
            }

            $date = $trip->trip_date->toDateString();
            $invAccount = AccountResolver::accountFor($item, $warehouse);
            $lines = [];

            // Step 1 — how the goods enter stock. Purchased goods are received here
            // (Dr Inventory / Cr Payable|Cash). Manufactured goods are already in
            // stock from their work order, so nothing is received — the journey
            // steps below are identical either way.
            $goodsCost = round((float) $trip->goods_cost, 4);
            if ($trip->source === 'purchase' && $goodsCost > 1e-9) {
                $this->inventory->receive($item, $warehouse, (float) $trip->quantity, (float) $trip->goods_rate, [
                    'posting_date' => $date, 'source' => $trip, 'voucher_no' => $trip->number,
                    'entry_type' => 'receipt', 'remarks' => 'Consignment trip goods',
                ]);
                $lines[] = LedgerLine::debit($invAccount, $goodsCost, 'Goods purchased');

                $creditId = $trip->goods_credit_account_id ?: $this->accountByControl('ap');
                if (! $creditId) {
                    throw new ConsignmentException('Choose an account to credit for the goods purchase.');
                }
                $isPayable = (int) $creditId === (int) $this->accountByControl('ap');
                $lines[] = new LedgerLine(
                    accountId: (int) $creditId,
                    credit: $goodsCost,
                    description: 'Goods purchased',
                    partyType: $isPayable && $trip->supplier ? $trip->supplier->getMorphClass() : null,
                    partyId: $isPayable && $trip->supplier ? $trip->supplier_id : null,
                );
            }

            // Steps 2..n — each leg's cost is capitalised onto the goods.
            foreach ($trip->steps as $step) {
                $amount = round((float) $step->amount, 4);
                if ($amount <= 1e-9) {
                    continue;
                }
                $this->inventory->addLandedCost($item, $warehouse, $amount, [
                    'posting_date' => $date, 'source' => $trip, 'voucher_no' => $trip->number,
                    'remarks' => $step->label ?: ucfirst($step->type),
                ]);
                $label = $step->label ?: ucfirst(str_replace('_', ' ', $step->type));
                $lines[] = LedgerLine::debit($invAccount, $amount, $label);

                $creditId = $step->credit_account_id ?: $this->accountByControl('cash');
                if (! $creditId) {
                    throw new ConsignmentException("Choose an account to credit for leg \"{$label}\".");
                }
                $lines[] = LedgerLine::credit((int) $creditId, $amount, $label);
                $step->update(['status' => 'done']);
            }

            $journalId = null;
            if (! empty($lines)) {
                $journal = $this->posting->post(new LedgerEntry(
                    entryDate: $date,
                    lines: $lines,
                    type: 'consignment_trip',
                    reference: $trip->number,
                    memo: $trip->memo ?: "Consignment trip {$trip->number}",
                    source: $trip,
                ));
                $this->inventory->stampJournal($trip, $journal->id);
                $journalId = $journal->id;
            }

            $logistics = round((float) $trip->steps->sum('amount'), 4);
            $trip->update([
                'status' => 'posted',
                'journal_id' => $journalId,
                'logistics_cost' => $logistics,
                'total_cost' => round($goodsCost + $logistics, 4),
                'posted_at' => now(),
            ]);

            return $trip->fresh('steps');
        });
    }

    /**
     * Sell the batch and record Profit/Loss. The goods are issued at their landed
     * weighted-average cost, so COGS already carries every leg's cost.
     *
     * @param  array<string, mixed>  $data  sale_amount, customer_id?, sale_account_id?, settlement_date?
     */
    public function settle(ConsignmentTrip $trip, array $data): ConsignmentTrip
    {
        if (! $trip->isPosted()) {
            throw new ConsignmentException('Only a posted trip can be settled.');
        }

        $trip->load('item', 'warehouse');
        $item = $trip->item;
        $warehouse = $trip->warehouse;
        $saleAmount = round((float) $data['sale_amount'], 4);
        if ($saleAmount < 0) {
            throw new ConsignmentException('The sale amount cannot be negative.');
        }

        return DB::transaction(function () use ($trip, $item, $warehouse, $saleAmount, $data) {
            $locked = ConsignmentTrip::whereKey($trip->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new ConsignmentException('This trip is no longer awaiting settlement.');
            }

            $date = $data['settlement_date'] ?? now()->toDateString();
            $customerId = $data['customer_id'] ?? $trip->customer_id;

            // Relieve stock at landed WAC — this cost already includes goods + every leg.
            $out = $this->inventory->issue($item, $warehouse, (float) $trip->quantity, [
                'posting_date' => $date, 'source' => $trip, 'voucher_no' => $trip->number,
                'entry_type' => 'issue', 'remarks' => 'Consignment trip sale',
            ]);
            $cost = round(abs((float) $out->value), 4);

            $revenueAccount = $item->income_account_id ?: $this->accountByCode('4100');
            $cogsAccount = $item->cogs_account_id ?: $this->accountByCode('5100');
            if (! $revenueAccount || ! $cogsAccount) {
                throw new ConsignmentException("Item {$item->code} needs revenue and COGS accounts.");
            }

            $debitId = $data['sale_account_id'] ?? $trip->sale_account_id ?? $this->accountByControl('ar');
            if (! $debitId) {
                throw new ConsignmentException('Choose an account to debit for the sale (receivable or bank).');
            }

            $customer = $customerId ? \App\Models\Customer::find($customerId) : null;
            $isReceivable = (int) $debitId === (int) $this->accountByControl('ar');

            $lines = [];
            $lines[] = new LedgerLine(
                accountId: (int) $debitId,
                debit: $saleAmount,
                description: 'Consignment trip sale',
                partyType: $isReceivable && $customer ? $customer->getMorphClass() : null,
                partyId: $isReceivable && $customer ? $customer->id : null,
            );
            $lines[] = LedgerLine::credit((int) $revenueAccount, $saleAmount, 'Consignment trip sale');
            if ($cost > 1e-9) {
                $lines[] = LedgerLine::debit((int) $cogsAccount, $cost, 'Cost of goods sold');
                $lines[] = LedgerLine::credit(AccountResolver::accountFor($item, $warehouse), $cost, 'Inventory relieved');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'consignment_trip_sale',
                reference: $trip->number,
                memo: "Consignment trip {$trip->number} settlement",
                source: $trip,
            ));

            // Stamp only the issue entry with the settlement journal (the earlier
            // receipt/landed-cost entries keep their posting journal).
            StockLedgerEntry::whereKey($out->id)->update(['journal_id' => $journal->id]);

            $trip->update([
                'status' => 'settled',
                'customer_id' => $customerId,
                'sale_account_id' => $debitId,
                'sale_amount' => $saleAmount,
                'cogs_total' => $cost,
                'profit' => round($saleAmount - $cost, 4),
                'settlement_journal_id' => $journal->id,
                'settled_at' => now(),
            ]);

            return $trip->fresh('steps');
        });
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'consignment_trip'],
            ['prefix' => 'TRIP-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('consignment_trip');
    }

    private function accountByControl(string $control): ?int
    {
        return Account::where('control_type', $control)->value('id');
    }

    private function accountByCode(string $code): ?int
    {
        return Account::where('code', $code)->value('id');
    }
}
