<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bom_id', 'item_id', 'source_warehouse_id', 'target_warehouse_id',
        'overhead_account_id', 'number', 'order_date', 'quantity', 'status',
        'material_cost', 'overhead_cost', 'produced_cost',
        'issue_journal_id', 'completion_journal_id', 'issued_at', 'completed_at', 'memo', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'quantity' => 'decimal:4',
            'material_cost' => 'decimal:4',
            'overhead_cost' => 'decimal:4',
            'produced_cost' => 'decimal:4',
            'issued_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class);
    }
}
