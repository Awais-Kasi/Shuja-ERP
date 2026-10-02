<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentSettlement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'consignment_dispatch_id', 'consignment_warehouse_id', 'agent_customer_id',
        'return_warehouse_id', 'number', 'settlement_date', 'subtotal', 'tax_amount', 'total',
        'commission_rate', 'commission_amount', 'cogs_total', 'return_freight', 'return_freight_account_id',
        'status', 'journal_id', 'reversal_journal_id', 'memo', 'posted_at', 'reversed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'commission_rate' => 'decimal:6',
            'commission_amount' => 'decimal:4',
            'cogs_total' => 'decimal:4',
            'return_freight' => 'decimal:4',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(ConsignmentDispatch::class, 'consignment_dispatch_id');
    }

    public function consignmentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'consignment_warehouse_id');
    }

    public function returnWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'return_warehouse_id');
    }

    public function agentCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'agent_customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsignmentSettlementLine::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
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
