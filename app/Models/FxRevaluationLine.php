<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxRevaluationLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'fx_revaluation_id', 'account_id', 'currency',
        'foreign_balance', 'closing_rate', 'carrying_base', 'revalued_base', 'adjustment',
    ];

    protected function casts(): array
    {
        return [
            'foreign_balance' => 'decimal:4',
            'closing_rate' => 'decimal:10',
            'carrying_base' => 'decimal:4',
            'revalued_base' => 'decimal:4',
            'adjustment' => 'decimal:4',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
