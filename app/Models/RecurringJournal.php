<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RecurringJournal extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'reference', 'memo', 'frequency', 'interval',
        'start_date', 'next_run_date', 'end_date', 'status', 'last_generated_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'next_run_date' => 'date',
            'end_date' => 'date',
            'last_generated_at' => 'datetime',
            'interval' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringJournalLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Journals posted from this template. */
    public function generated(): MorphMany
    {
        return $this->morphMany(Journal::class, 'source');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
