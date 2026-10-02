<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentSettlementLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'consignment_settlement_id', 'consignment_dispatch_line_id', 'item_id',
        'sold_qty', 'rate', 'amount', 'sold_cost', 'returned_cost', 'returned_qty', 'description',
    ];

    protected function casts(): array
    {
        return [
            'sold_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
            'sold_cost' => 'decimal:4',
            'returned_cost' => 'decimal:4',
            'returned_qty' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
