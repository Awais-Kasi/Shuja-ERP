<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentExpense extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'consignment_dispatch_id', 'warehouse_id', 'credit_account_id',
        'number', 'expense_date', 'allocation_basis', 'party_type', 'party_id',
        'status', 'journal_id', 'reversal_journal_id', 'memo', 'posted_at', 'reversed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(ConsignmentDispatch::class, 'consignment_dispatch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentExpenseLine::class);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}
