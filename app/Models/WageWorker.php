<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A daily-wage (dihari) worker paid per unit of work (e.g. per KG). Their wallet
 * balance = total earned (wage_entries) - total paid (wage_payments).
 */
class WageWorker extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name', 'cnic', 'phone', 'rate_per_kg', 'is_active'];

    protected $casts = [
        'rate_per_kg' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(WageEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(WagePayment::class);
    }

    /** What we currently owe this worker (earned - paid). */
    public function walletBalance(): float
    {
        return round((float) $this->entries()->sum('amount') - (float) $this->payments()->sum('amount'), 2);
    }
}
