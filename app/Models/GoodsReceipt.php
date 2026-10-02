<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'supplier_id', 'purchase_order_id', 'warehouse_id', 'number',
        'receipt_date', 'supplier_dn_no', 'status', 'total_value', 'journal_id',
        'billed', 'memo', 'posted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'total_value' => 'decimal:4',
            'billed' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
