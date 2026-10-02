<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepreciationRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'period_year', 'period_month', 'run_date', 'status',
        'total_amount', 'journal_id', 'memo', 'created_by',
    ];

    protected function casts(): array
    {
        return ['run_date' => 'date', 'total_amount' => 'decimal:4'];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationRunLine::class);
    }
}
