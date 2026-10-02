<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active company for the authenticated user and binds it to the
 * tenant context for the rest of the request. Order: runs after the session
 * is started and before Inertia shares data.
 */
class SetCurrentCompany
{
    public function __construct(protected TenantManager $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $companyId = $request->session()->get('current_company_id')
                ?? $user->default_company_id
                ?? $user->companies()->value('companies.id');

            if ($companyId) {
                $company = Company::find($companyId);

                // Only bind if the user actually belongs to it (or is super admin).
                if ($company && ($user->is_super_admin || $user->belongsToCompany($company->id))) {
                    $this->tenant->set($company);
                    $request->session()->put('current_company_id', $company->id);
                }
            }
        }

        return $next($request);
    }
}
