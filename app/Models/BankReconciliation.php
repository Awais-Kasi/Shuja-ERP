<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bank_account_id', 'statement_date',
        'opening_balance', 'statement_balance', 'status', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'opening_balance' => 'decimal:4',
            'statement_balance' => 'decimal:4',
            'completed_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /** Sum of the lines cleared in this reconciliation (signed toward the bank balance). */
    public function clearedTotal(): float
    {
        return round((float) $this->lines->sum('amount'), 4);
    }

    /** Opening cleared balance + everything cleared here — what the statement should show. */
    public function reconciledBalance(): float
    {
        return round((float) $this->opening_balance + $this->clearedTotal(), 4);
    }

    /** Zero when the reconciliation ties out to the statement. */
    public function difference(): float
    {
        return round((float) $this->statement_balance - $this->reconciledBalance(), 4);
    }
}
