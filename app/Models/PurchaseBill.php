<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseBill extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'supplier_id', 'goods_receipt_id', 'number', 'supplier_invoice_no',
        'bill_date', 'due_date', 'status', 'currency', 'fx_rate', 'subtotal', 'tax_amount', 'total',
        'amount_paid', 'journal_id', 'memo', 'posted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'fx_rate' => 'decimal:10',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /** Amount still owed on this bill. */
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class);
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
