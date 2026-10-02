<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'journal_id', 'account_id', 'cost_center_id', 'line_no',
        'description', 'debit', 'credit', 'currency', 'fx_rate',
        'base_debit', 'base_credit', 'party_type', 'party_id',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'fx_rate' => 'decimal:10',
            'base_debit' => 'decimal:4',
            'base_credit' => 'decimal:4',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
    }
}
