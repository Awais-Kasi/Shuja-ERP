<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'customer_id', 'warehouse_id', 'number', 'order_date',
        'expected_date', 'status', 'subtotal', 'memo', 'created_by',
    ];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'expected_date' => 'date', 'subtotal' => 'decimal:4'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }
}
