<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model. Adds the global company scope and
 * stamps company_id on create from the current tenant context.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model): void {
            if (empty($model->company_id)) {
                $tenant = app(TenantManager::class);
                if ($tenant->has()) {
                    $model->company_id = $tenant->id();
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Query without the company scope (use deliberately). */
    public function scopeAcrossCompanies($query)
    {
        return $query->withoutGlobalScope(CompanyScope::class);
    }
}
