<?php

namespace App\Manufacturing;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\WorkOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Drives a work order through production:
 *
 *  issueMaterials(): consume components at cost →  Dr WIP / Cr Raw Material Inventory
 *  complete():       apply overhead and receive the finished good at its produced
 *                    unit cost →  Dr WIP / Cr Overhead, then Dr Finished Goods / Cr WIP
 *
 * Across both steps WIP nets to zero and the finished good enters stock at
 * (materials + overhead) / quantity.
 */
class ProductionService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function issueMaterials(WorkOrder $wo): WorkOrder
    {
        if ($wo->status !== 'draft') {
            throw new ManufacturingException('Materials can only be issued for a draft work order.');
        }

        $wo->load('lines.componentItem');
        if ($wo->lines->isEmpty()) {
            throw new ManufacturingException('This work order has no components to issue.');
        }

        $wip = Account::where('control_type', 'wip')->first();
        if (! $wip) {
            throw new ManufacturingException('No Work-in-Progress (wip) control account is configured.');
        }

        return DB::transaction(function () use ($wo, $wip) {
            $date = $wo->order_date->toDateString();
            $inventoryByAccount = [];
            $materialCost = 0.0;

            foreach ($wo->lines as $line) {
                $item = $line->componentItem;
                if (! $item->inventory_account_id) {
                    throw new ManufacturingException("Component {$item->code} has no inventory account mapped.");
                }
                if ((float) $line->quantity <= 0) {
                    continue;
                }

                $sle = $this->inventory->issue($item, $wo->sourceWarehouse, (float) $line->quantity, [
                    'posting_date' => $date,
                    'entry_type' => 'issue',
                    'source' => $wo,
                    'voucher_no' => $wo->number,
                    'remarks' => 'Production issue',
                ]);
                $cost = abs((float) $sle->value);
                $materialCost = round($materialCost + $cost, 4);
                $inventoryByAccount[$item->inventory_account_id] = round(($inventoryByAccount[$item->inventory_account_id] ?? 0) + $cost, 4);

                $line->update(['issued_qty' => $line->quantity]);
            }

            $lines = [LedgerLine::debit($wip->id, $materialCost, 'WIP — materials')];
            foreach ($inventoryByAccount as $accountId => $cost) {
                if ($cost > 0.005) {
                    $lines[] = LedgerLine::credit((int) $accountId, $cost, 'Materials issued to production');
                }
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'production_issue',
                reference: $wo->number,
                memo: "Materials issued for {$wo->number}",
                source: $wo,
            ));
            $this->inventory->stampJournal($wo, $journal->id);

            $wo->update([
                'status' => 'in_progress',
                'material_cost' => $materialCost,
                'issue_journal_id' => $journal->id,
                'issued_at' => now(),
            ]);

            return $wo;
        });
    }

    public function complete(WorkOrder $wo, float $overhead = 0, ?int $overheadAccountId = null): WorkOrder
    {
        if ($wo->status !== 'in_progress') {
            throw new ManufacturingException('Only a work order with materials issued can be completed.');
        }

        $wo->load('item');
        if (! $wo->item->inventory_account_id) {
            throw new ManufacturingException("Output item {$wo->item->code} has no inventory account mapped.");
        }

        $wip = Account::where('control_type', 'wip')->first();
        $overhead = round(max($overhead, 0), 4);

        return DB::transaction(function () use ($wo, $wip, $overhead, $overheadAccountId) {
            $date = $wo->order_date->toDateString();
            $totalCost = round((float) $wo->material_cost + $overhead, 4);
            $qty = (float) $wo->quantity;
            $unitCost = $qty > 0 ? $totalCost / $qty : 0.0;

            // Receive finished goods at produced unit cost.
            $sle = $this->inventory->receive($wo->item, $wo->targetWarehouse, $qty, $unitCost, [
                'posting_date' => $date,
                'entry_type' => 'receipt',
                'source' => $wo,
                'voucher_no' => $wo->number,
                'remarks' => 'Production output',
            ]);
            $fgValue = abs((float) $sle->value);

            $lines = [];
            if ($overhead > 0.005) {
                $overheadAccount = $overheadAccountId
                    ? Account::find($overheadAccountId)
                    : ($wo->overhead_account_id ? Account::find($wo->overhead_account_id) : Account::where('code', '5200')->first());
                if (! $overheadAccount) {
                    throw new ManufacturingException('No overhead account configured (default 5200 missing).');
                }
                $lines[] = LedgerLine::debit($wip->id, $overhead, 'WIP — overhead');
                $lines[] = LedgerLine::credit($overheadAccount->id, $overhead, 'Overhead applied');
            }
            $lines[] = LedgerLine::debit($wo->item->inventory_account_id, $fgValue, 'Finished goods produced');
            $lines[] = LedgerLine::credit($wip->id, $fgValue, 'WIP relieved to finished goods');

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'production_complete',
                reference: $wo->number,
                memo: "Production completed for {$wo->number}",
                source: $wo,
            ));
            $sle->update(['journal_id' => $journal->id]); // only the FG receipt; material SLEs keep the issue journal

            $wo->update([
                'status' => 'completed',
                'overhead_cost' => $overhead,
                'produced_cost' => $fgValue,
                'overhead_account_id' => $overheadAccountId ?? $wo->overhead_account_id,
                'completion_journal_id' => $journal->id,
                'completed_at' => now(),
            ]);

            return $wo;
        });
    }
}
