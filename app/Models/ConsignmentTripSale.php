<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single customer's share of a consignment trip's goods at settlement.
 */
class ConsignmentTripSale extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'consignment_trip_id', 'customer_id', 'quantity', 'amount', 'cost', 'profit',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'amount' => 'decimal:4',
        'cost' => 'decimal:4',
        'profit' => 'decimal:4',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(ConsignmentTrip::class, 'consignment_trip_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
