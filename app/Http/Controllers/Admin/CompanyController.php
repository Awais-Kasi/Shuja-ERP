<?php

namespace App\Http\Controllers\Admin;

use App\Companies\CompanyProvisioner;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $query = $user->is_super_admin ? Company::query() : Company::whereIn('id', $user->companies()->pluck('companies.id'));

        $companies = $query->orderBy('name')->get()->map(fn (Company $c) => [
            'id' => $c->id, 'name' => $c->name, 'legal_name' => $c->legal_name, 'code' => $c->code,
            'base_currency' => $c->base_currency, 'country' => $c->country, 'timezone' => $c->timezone,
            'fiscal_start_month' => $c->fiscal_start_month, 'tax_registration_no' => $c->tax_registration_no,
            'address' => $c->address, 'is_active' => (bool) $c->is_active,
        ]);

        return Inertia::render('admin/companies/index', [
            'companies' => $companies,
            'currencies' => \App\Models\Currency::where('is_active', true)->orderBy('code')->pluck('code'),
        ]);
    }

    public function store(Request $request, CompanyProvisioner $provisioner): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', Rule::unique('companies', 'code')],
            'base_currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'country' => ['nullable', 'string', 'max:2'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'fiscal_start_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $company = $provisioner->provision($validated, Auth::user());

        return back()->with('success', "Company {$company->name} created and provisioned.");
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        abort_unless(Auth::user()->is_super_admin || Auth::user()->belongsToCompany($company->id), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'tax_registration_no' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $company->update($validated);

        return back()->with('success', 'Company updated.');
    }
}
