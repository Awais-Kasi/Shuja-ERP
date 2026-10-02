<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentDispatch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'from_warehouse_id', 'to_warehouse_id', 'agent_customer_id', 'commission_rate', 'via_transit',
        'number', 'dispatch_date', 'status', 'journal_id', 'reversal_journal_id',
        'memo', 'posted_at', 'received_at', 'reversed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['dispatch_date' => 'date', 'commission_rate' => 'decimal:6', 'via_transit' => 'boolean', 'posted_at' => 'datetime', 'received_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function agentCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'agent_customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentDispatchLine::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ConsignmentExpense::class, 'consignment_dispatch_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(ConsignmentSettlement::class, 'consignment_dispatch_id');
    }

    public function isPosted(): bool
    {
        return $this->status !== 'draft';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}
