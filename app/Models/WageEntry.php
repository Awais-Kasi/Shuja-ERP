<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Work done by a daily-wage worker (earned amount = quantity * rate). */
class WageEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'wage_worker_id', 'number', 'entry_date', 'quantity', 'rate', 'amount',
        'description', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'quantity' => 'decimal:4',
        'rate' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(WageWorker::class, 'wage_worker_id');
    }
}
