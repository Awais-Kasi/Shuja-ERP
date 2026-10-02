<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockLedgerEntry extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'company_id', 'item_id', 'warehouse_id', 'posting_date', 'entry_type',
        'quantity', 'rate', 'value', 'balance_qty', 'balance_value',
        'valuation_method', 'journal_id', 'source_type', 'source_id',
        'voucher_no', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'value' => 'decimal:4',
            'balance_qty' => 'decimal:4',
            'balance_value' => 'decimal:4',
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

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
