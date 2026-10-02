<?php

namespace App\Inventory;

use App\Models\NumberSequence;
use App\Models\StockTransfer;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Posts a warehouse-to-warehouse transfer. Stock (and its valued cost) leaves
 * the source warehouse and enters the destination at the same relieved rate, so
 * total inventory value is unchanged — no GL entry is required when both
 * warehouses share an inventory account.
 */
class StockTransferService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly TenantManager $tenant,
    ) {}

    public function post(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->isPosted()) {
            throw new InventoryException('This transfer is already posted.');
        }
        if ($transfer->from_warehouse_id === $transfer->to_warehouse_id) {
            throw new InventoryException('Source and destination warehouses must differ.');
        }

        $transfer->load('lines.item', 'fromWarehouse', 'toWarehouse');

        if ($transfer->lines->isEmpty()) {
            throw new InventoryException('Add at least one line before posting.');
        }

        return DB::transaction(function () use ($transfer) {
            $date = $transfer->transfer_date->toDateString();
            $number = $this->allocateNumber();

            foreach ($transfer->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;

                if ($qty <= 0) {
                    continue;
                }

                $out = $this->inventory->issue($item, $transfer->fromWarehouse, $qty, [
                    'posting_date' => $date,
                    'entry_type' => 'transfer_out',
                    'source' => $transfer,
                    'voucher_no' => $number,
                    'remarks' => $line->description,
                ]);

                $rate = abs((float) $out->value) / $qty;

                $this->inventory->receive($item, $transfer->toWarehouse, $qty, $rate, [
                    'posting_date' => $date,
                    'entry_type' => 'transfer_in',
                    'source' => $transfer,
                    'voucher_no' => $number,
                    'remarks' => $line->description,
                ]);
            }

            $transfer->update([
                'number' => $number,
                'status' => 'posted',
                'posted_at' => now(),
            ]);

            return $transfer;
        });
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'stock_transfer'],
            ['prefix' => 'TRF-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('stock_transfer');
    }
}
