<?php

namespace App\Consignment;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\ConsignmentTrip;
use App\Models\ConsignmentTripSale;
use App\Models\ConsignmentTripStep;
use App\Models\Customer;
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
    /**
     * Settle (sell) the trip across one or more customers. Each line is
     * {customer_id, quantity, amount}: the goods are relieved at landed WAC per
     * line and the sale value is booked as a receivable stamped to that customer,
     * so every buyer's khata is maintained from this one sale. Batch
     * Profit/Loss = total sale - total landed cost relieved.
     *
     * @param  array<string, mixed>  $data  lines[], settlement_date?
     */
    public function settle(ConsignmentTrip $trip, array $data): ConsignmentTrip
    {
        if (! $trip->isPosted()) {
            throw new ConsignmentException('Only a posted trip can be settled.');
        }

        $saleLines = array_values($data['lines'] ?? []);
        if (empty($saleLines)) {
            throw new ConsignmentException('Add at least one customer to sell to.');
        }

        $trip->load('item', 'warehouse');
        $item = $trip->item;
        $warehouse = $trip->warehouse;

        $totalQty = round(array_sum(array_map(fn ($l) => (float) $l['quantity'], $saleLines)), 4);
        if ($totalQty <= 0) {
            throw new ConsignmentException('Total quantity to sell must be more than zero.');
        }
        if ($totalQty > (float) $trip->quantity + 1e-6) {
            throw new ConsignmentException("You are selling {$totalQty} but the trip only has ".rtrim(rtrim(number_format((float) $trip->quantity, 4), '0'), '.').' in stock.');
        }

        $revenueAccount = $item->income_account_id ?: $this->accountByCode('4100');
        $cogsAccount = $item->cogs_account_id ?: $this->accountByCode('5100');
        $arAccount = $this->accountByControl('ar');
        if (! $revenueAccount || ! $cogsAccount) {
            throw new ConsignmentException("Item {$item->code} needs revenue and COGS accounts.");
        }
        if (! $arAccount) {
            throw new ConsignmentException('No Accounts Receivable control account is configured.');
        }

        return DB::transaction(function () use ($trip, $item, $warehouse, $saleLines, $data, $revenueAccount, $cogsAccount, $arAccount) {
            $locked = ConsignmentTrip::whereKey($trip->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new ConsignmentException('This trip is no longer awaiting settlement.');
            }

            $date = $data['settlement_date'] ?? now()->toDateString();
            $invAccount = AccountResolver::accountFor($item, $warehouse);

            $ledger = [];
            $saleRows = [];
            $issuedIds = [];
            $totalSale = 0.0;
            $totalCost = 0.0;

            foreach ($saleLines as $line) {
                $qty = round((float) $line['quantity'], 4);
                $amount = round((float) $line['amount'], 4);
                if ($qty <= 0) {
                    continue;
                }
                $customer = Customer::find($line['customer_id']);
                if (! $customer) {
                    throw new ConsignmentException('Choose a valid customer for every sale line.');
                }

                // Relieve this customer's share at landed WAC (goods + every leg cost).
                $out = $this->inventory->issue($item, $warehouse, $qty, [
                    'posting_date' => $date, 'source' => $trip, 'voucher_no' => $trip->number,
                    'entry_type' => 'issue', 'remarks' => "Trip sale to {$customer->code}",
                ]);
                $cost = round(abs((float) $out->value), 4);
                $issuedIds[] = $out->id;

                // Receivable stamped to this customer -> shows in their khata.
                $ledger[] = new LedgerLine(
                    accountId: (int) $arAccount,
                    debit: $amount,
                    description: "Trip {$trip->number} sale",
                    partyType: $customer->getMorphClass(),
                    partyId: $customer->id,
                );

                $saleRows[] = [
                    'company_id' => $trip->company_id,
                    'customer_id' => $customer->id,
                    'quantity' => $qty,
                    'amount' => $amount,
                    'cost' => $cost,
                    'profit' => round($amount - $cost, 4),
                ];

                $totalSale = round($totalSale + $amount, 4);
                $totalCost = round($totalCost + $cost, 4);
            }

            if (empty($saleRows)) {
                throw new ConsignmentException('Add at least one customer with a quantity to sell.');
            }

            // Revenue and COGS aggregated (one line each keeps the journal tidy).
            $ledger[] = LedgerLine::credit((int) $revenueAccount, $totalSale, "Trip {$trip->number} sales");
            if ($totalCost > 1e-9) {
                $ledger[] = LedgerLine::debit((int) $cogsAccount, $totalCost, 'Cost of goods sold');
                $ledger[] = LedgerLine::credit($invAccount, $totalCost, 'Inventory relieved');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $ledger,
                type: 'consignment_trip_sale',
                reference: $trip->number,
                memo: "Consignment trip {$trip->number} settlement",
                source: $trip,
            ));

            // Stamp the issue entries with the settlement journal (earlier
            // receipt/landed-cost entries keep their posting journal).
            StockLedgerEntry::whereIn('id', $issuedIds)->update(['journal_id' => $journal->id]);

            foreach ($saleRows as $row) {
                ConsignmentTripSale::create(array_merge($row, ['consignment_trip_id' => $trip->id]));
            }

            $trip->update([
                'status' => 'settled',
                'customer_id' => count($saleRows) === 1 ? $saleRows[0]['customer_id'] : null,
                'sale_account_id' => $arAccount,
                'sale_amount' => $totalSale,
                'cogs_total' => $totalCost,
                'profit' => round($totalSale - $totalCost, 4),
                'settlement_journal_id' => $journal->id,
                'settled_at' => now(),
            ]);

            return $trip->fresh('steps', 'sales');
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
