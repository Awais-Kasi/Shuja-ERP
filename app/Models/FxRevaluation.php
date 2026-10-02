<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxRevaluation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'revaluation_date', 'status', 'total_gain', 'total_loss', 'journal_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'revaluation_date' => 'date',
            'total_gain' => 'decimal:4',
            'total_loss' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FxRevaluationLine::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
