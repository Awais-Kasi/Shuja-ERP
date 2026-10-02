<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'sales_return_id', 'sales_invoice_line_id', 'item_id',
        'quantity', 'rate', 'amount', 'cost',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'rate' => 'decimal:4', 'amount' => 'decimal:4', 'cost' => 'decimal:4'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id');
    }
}
