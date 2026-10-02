<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'number', 'transfer_date', 'from_warehouse_id', 'to_warehouse_id',
        'memo', 'status', 'journal_id', 'posted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
