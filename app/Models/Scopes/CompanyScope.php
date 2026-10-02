<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Automatically constrains queries on tenant-scoped models to the current
 * company. When no company is bound (console, seeding, cross-company work)
 * the scope is inert, so explicit queries still function.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantManager::class);

        if ($tenant->has()) {
            $builder->where($model->getTable().'.company_id', $tenant->id());
        }
    }
}
