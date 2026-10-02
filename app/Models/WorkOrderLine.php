<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'work_order_id', 'component_item_id', 'quantity', 'issued_qty',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'issued_qty' => 'decimal:4'];
    }

    public function componentItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}
