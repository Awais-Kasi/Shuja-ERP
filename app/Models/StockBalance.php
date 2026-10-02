<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'item_id', 'warehouse_id', 'quantity', 'value',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'value' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function averageRate(): float
    {
        $qty = (float) $this->quantity;

        return $qty != 0.0 ? round((float) $this->value / $qty, 4) : 0.0;
    }
}
