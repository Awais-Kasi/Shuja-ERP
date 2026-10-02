<?php

namespace App\Purchasing;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\NumberSequence;
use App\Models\PurchaseOrderLine;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Posts a goods receipt: receives each line into stock through the valuation
 * engine and books Dr Inventory / Cr Goods-Received-Not-Invoiced (GRNI). The
 * GRNI accrual is later cleared by the supplier's purchase bill.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(GoodsReceipt $grn): GoodsReceipt
    {
        if ($grn->isPosted()) {
            throw new PurchasingException('This goods receipt is already posted.');
        }

        $grn->load('lines.item');
        if ($grn->lines->isEmpty()) {
            throw new PurchasingException('Add at least one line before posting.');
        }

        $grni = Account::where('control_type', 'grni')->first();
        if (! $grni) {
            throw new PurchasingException('No "Goods Received Not Invoiced" (grni) control account is configured.');
        }

        return DB::transaction(function () use ($grn, $grni) {
            $date = $grn->receipt_date->toDateString();
            $number = $this->allocateNumber();

            $inventoryNet = [];
            $total = 0.0;

            foreach ($grn->lines as $line) {
                $item = $line->item;
                if (! $item->inventory_account_id) {
                    throw new PurchasingException("Item {$item->code} has no inventory account mapped.");
                }

                $this->inventory->receive($item, $grn->warehouse, (float) $line->quantity, (float) $line->rate, [
                    'posting_date' => $date,
                    'entry_type' => 'receipt',
                    'source' => $grn,
                    'voucher_no' => $number,
                    'remarks' => $line->description,
                ]);

                $value = round((float) $line->quantity * (float) $line->rate, 4);
                $inventoryNet[$item->inventory_account_id] = round(($inventoryNet[$item->inventory_account_id] ?? 0) + $value, 4);
                $total = round($total + $value, 4);

                if ($line->purchase_order_line_id) {
                    PurchaseOrderLine::whereKey($line->purchase_order_line_id)->increment('received_qty', (float) $line->quantity);
                }
            }

            $lines = [];
            foreach ($inventoryNet as $accountId => $value) {
                if ($value > 0.005) {
                    $lines[] = LedgerLine::debit((int) $accountId, $value, 'Goods received');
                }
            }
            $lines[] = LedgerLine::credit($grni->id, $total, 'Goods received not invoiced');

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'goods_receipt',
                reference: $number,
                memo: $grn->memo ?: "Goods receipt {$number}",
                source: $grn,
            ));

            $this->inventory->stampJournal($grn, $journal->id);

            $grn->update([
                'number' => $number,
                'status' => 'posted',
                'journal_id' => $journal->id,
                'total_value' => $total,
                'posted_at' => now(),
            ]);

            $this->syncPurchaseOrderStatus($grn);

            return $grn;
        });
    }

    private function syncPurchaseOrderStatus(GoodsReceipt $grn): void
    {
        if (! $grn->purchase_order_id) {
            return;
        }

        $po = $grn->purchaseOrder()->with('lines')->first();
        if (! $po) {
            return;
        }

        $fullyReceived = $po->lines->every(fn ($l) => (float) $l->received_qty >= (float) $l->quantity - 1e-9);
        $po->update(['status' => $fullyReceived ? 'received' : 'confirmed']);
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'grn'],
            ['prefix' => 'GRN-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('grn');
    }
}
