<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentDispatchLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'consignment_dispatch_id', 'item_id', 'quantity',
        'dispatch_rate', 'dispatch_value', 'settled_qty', 'returned_qty', 'description',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'dispatch_rate' => 'decimal:4',
            'dispatch_value' => 'decimal:4',
            'settled_qty' => 'decimal:4',
            'returned_qty' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
