<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAsset extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'code', 'name', 'category',
        'asset_account_id', 'accum_account_id', 'depreciation_account_id', 'cost_center_id',
        'cost', 'salvage_value', 'useful_life_months', 'depreciation_method', 'acquisition_date',
        'accumulated_depreciation', 'status', 'journal_id', 'disposal_journal_id', 'disposed_at',
        'memo', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'useful_life_months' => 'integer',
            'acquisition_date' => 'date',
            'accumulated_depreciation' => 'decimal:4',
            'disposed_at' => 'date',
        ];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_account_id');
    }

    /** The maximum that may ever be depreciated. */
    public function depreciableBase(): float
    {
        return round((float) $this->cost - (float) $this->salvage_value, 4);
    }

    /** Depreciation still to be charged over the asset's remaining life. */
    public function remainingDepreciable(): float
    {
        return round($this->depreciableBase() - (float) $this->accumulated_depreciation, 4);
    }

    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 4);
    }

    /** Straight-line monthly charge; the final scheduled month clears the exact remainder. */
    public function monthlyDepreciation(): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        $remaining = max(0.0, $this->remainingDepreciable());
        if ($remaining <= 1e-9) {
            return 0.0;
        }

        $straight = round($this->depreciableBase() / $this->useful_life_months, 4);
        if ($straight <= 0) {
            return $remaining;
        }

        // On the last scheduled instalment, charge whatever is left so 4dp rounding
        // never leaves a sub-cent residual for an extra month.
        $taken = (int) round((float) $this->accumulated_depreciation / $straight);
        if ($taken >= $this->useful_life_months - 1) {
            return $remaining;
        }

        return min($straight, $remaining);
    }
}
