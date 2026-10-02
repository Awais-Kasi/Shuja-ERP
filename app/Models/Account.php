<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'parent_id', 'code', 'name', 'type', 'is_group',
        'control_type', 'currency', 'is_active', 'description',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_group' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function normalBalance(): string
    {
        return $this->type->normalBalance();
    }

    /** Only leaf (non-group), active accounts may receive postings. */
    public function isPostable(): bool
    {
        return ! $this->is_group && $this->is_active;
    }
}
