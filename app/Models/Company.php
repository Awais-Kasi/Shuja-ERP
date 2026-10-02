<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'legal_name', 'code', 'base_currency', 'country', 'timezone',
        'fiscal_start_month', 'default_valuation_method', 'allow_negative_stock',
        'tax_registration_no', 'address', 'tax_config', 'settings', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tax_config' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'fiscal_start_month' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot(['role_id', 'is_default'])
            ->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function numberSequences(): HasMany
    {
        return $this->hasMany(NumberSequence::class);
    }
}
