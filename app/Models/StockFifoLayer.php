<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockFifoLayer extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'item_id', 'warehouse_id', 'sle_id',
        'posting_date', 'rate', 'original_qty', 'remaining_qty',
    ];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'rate' => 'decimal:4',
            'original_qty' => 'decimal:4',
            'remaining_qty' => 'decimal:4',
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
}
