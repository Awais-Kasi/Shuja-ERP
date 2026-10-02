<?php

namespace App\Consignment;

use App\Models\Item;
use App\Models\Warehouse;

/**
 * Single source of truth for which GL inventory account a given (item, warehouse)
 * pair posts to. A warehouse-level override (e.g. consignment → 1124, transit →
 * 1140) wins; otherwise the item's own inventory account is used. This is what
 * routes consignment stock to its own balance-sheet line without touching
 * InventoryService (which never posts GL).
 */
class AccountResolver
{
    public static function accountFor(Item $item, Warehouse $warehouse): int
    {
        $id = $warehouse->inventory_account_id ?? $item->inventory_account_id;

        if (! $id) {
            throw new ConsignmentException("No inventory account resolves for item {$item->code} in warehouse {$warehouse->code}.");
        }

        return (int) $id;
    }
}
