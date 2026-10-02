<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'customer_id', 'sales_order_id', 'warehouse_id', 'number',
        'invoice_date', 'due_date', 'status', 'currency', 'fx_rate', 'subtotal', 'tax_amount', 'total',
        'amount_paid', 'cogs_total', 'journal_id', 'memo', 'posted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'fx_rate' => 'decimal:10',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'cogs_total' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /** Amount still owed on this invoice. */
    public function outstanding(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 4);
    }

    public function settlementStatus(): string
    {
        if ($this->outstanding() <= 0.0001) {
            return 'paid';
        }

        return (float) $this->amount_paid > 0.0001 ? 'partial' : 'unpaid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
