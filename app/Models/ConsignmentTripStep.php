<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg of a consignment trip: a cost incurred at a location, charged on a
 * per-bag / per-kg / flat basis, optionally against its own truck number.
 */
class ConsignmentTripStep extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'consignment_trip_id', 'sequence', 'type', 'label', 'location',
        'vehicle_no', 'basis', 'rate', 'amount', 'credit_account_id', 'status',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'rate' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(ConsignmentTrip::class, 'consignment_trip_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    /**
     * Compute the leg amount from its basis against the trip's quantity (kg) and
     * package (bag) count. Keeps every amount derived from a single source of truth.
     */
    public static function computeAmount(string $basis, float $rate, float $quantity, int $packages): float
    {
        return match ($basis) {
            'per_bag' => round($rate * $packages, 4),
            'per_kg' => round($rate * $quantity, 4),
            default => round($rate, 4), // flat
        };
    }
}
