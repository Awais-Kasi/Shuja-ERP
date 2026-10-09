<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A landed-cost journey for one batch of goods under a truck/vehicle number.
 * Costs accumulate onto the goods as the batch travels; settlement sells the
 * goods and records the batch Profit/Loss. See the create-tables migration for
 * the full picture.
 */
class ConsignmentTrip extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'number', 'vehicle_no', 'source', 'item_id', 'warehouse_id',
        'supplier_id', 'customer_id', 'origin', 'destination', 'quantity', 'packages',
        'goods_rate', 'goods_cost', 'goods_credit_account_id', 'logistics_cost', 'total_cost',
        'sale_account_id', 'sale_amount', 'cogs_total', 'profit', 'status', 'trip_date',
        'memo', 'journal_id', 'settlement_journal_id', 'posted_at', 'settled_at', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'packages' => 'integer',
        'goods_rate' => 'decimal:4',
        'goods_cost' => 'decimal:4',
        'logistics_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'sale_amount' => 'decimal:4',
        'cogs_total' => 'decimal:4',
        'profit' => 'decimal:4',
        'trip_date' => 'date',
        'posted_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(ConsignmentTripStep::class)->orderBy('sequence')->orderBy('id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }
}
