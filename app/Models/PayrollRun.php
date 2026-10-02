<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'number', 'period_year', 'period_month', 'accrual_date', 'status',
        'gross_total', 'deduction_total', 'lop_total', 'employer_contrib_total', 'net_total',
        'journal_id', 'reversal_journal_id', 'memo', 'posted_at', 'reversed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'accrual_date' => 'date',
            'gross_total' => 'decimal:4',
            'deduction_total' => 'decimal:4',
            'lop_total' => 'decimal:4',
            'employer_contrib_total' => 'decimal:4',
            'net_total' => 'decimal:4',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
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
        return $this->status !== 'draft';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}
