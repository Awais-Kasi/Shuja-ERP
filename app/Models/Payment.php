<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'direction', 'party_type', 'party_id', 'number', 'payment_date',
        'account_id', 'amount', 'currency', 'fx_rate', 'reference', 'memo', 'status', 'journal_id', 'reversal_journal_id',
        'posted_at', 'reversed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:4',
            'fx_rate' => 'decimal:10',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function isReceipt(): bool
    {
        return $this->direction === 'receive';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function allocatedTotal(): float
    {
        return round((float) $this->allocations->sum('amount'), 4);
    }

    /** Amount not applied to any document (an advance / on-account balance). */
    public function unappliedAmount(): float
    {
        return round((float) $this->amount - $this->allocatedTotal(), 4);
    }
}
