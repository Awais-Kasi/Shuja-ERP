<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid to a daily-wage worker (daily / weekly / on demand). */
class WagePayment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'wage_worker_id', 'number', 'payment_date', 'amount',
        'account_id', 'reference', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:4',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(WageWorker::class, 'wage_worker_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
