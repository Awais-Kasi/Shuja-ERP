<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'sales_invoice_id', 'item_id', 'sales_order_line_id',
        'description', 'quantity', 'rate', 'amount', 'cost', 'returned_qty',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'rate' => 'decimal:4', 'amount' => 'decimal:4', 'cost' => 'decimal:4', 'returned_qty' => 'decimal:4'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
