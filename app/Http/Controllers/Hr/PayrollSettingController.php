<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\PayrollSetting;
use App\Models\PayrollTaxSlab;
use App\Payroll\PayrollConfig;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollSettingController extends Controller
{
    public function index(): Response
    {
        $config = PayrollConfig::forCompany();

        return Inertia::render('hr/payroll/settings', [
            'settings' => [
                'eobi_wage_base' => $config->eobiWageBase,
                'eobi_employee_rate' => $config->eobiEmployeeRate,
                'eobi_employer_rate' => $config->eobiEmployerRate,
                'pf_rate' => $config->pfRate,
            ],
            'slabs' => collect($config->taxSlabs)->map(fn ($s) => [
                'lower_bound' => (float) $s[0],
                'base_tax' => (float) $s[1],
                'rate' => (float) $s[2],
            ])->values(),
            'defaults' => [
                'eobi_wage_base' => PayrollConfig::DEFAULT_EOBI_WAGE_BASE,
                'eobi_employee_rate' => PayrollConfig::DEFAULT_EOBI_EMPLOYEE_RATE,
                'eobi_employer_rate' => PayrollConfig::DEFAULT_EOBI_EMPLOYER_RATE,
                'pf_rate' => PayrollConfig::DEFAULT_PF_RATE,
            ],
        ]);
    }

    public function update(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'eobi_wage_base' => ['required', 'numeric', 'gte:0'],
            'eobi_employee_rate' => ['required', 'numeric', 'gte:0', 'lte:1'],
            'eobi_employer_rate' => ['required', 'numeric', 'gte:0', 'lte:1'],
            'pf_rate' => ['required', 'numeric', 'gte:0', 'lte:1'],
            'slabs' => ['required', 'array', 'min:1'],
            'slabs.*.lower_bound' => ['required', 'numeric', 'gte:0'],
            'slabs.*.base_tax' => ['required', 'numeric', 'gte:0'],
            'slabs.*.rate' => ['required', 'numeric', 'gte:0', 'lte:1'],
        ]);

        // Slabs must start at 0 and ascend, so the calculator's "last band whose lower
        // bound is below the income" walk is well-defined.
        $slabs = collect($validated['slabs'])->sortBy('lower_bound')->values();
        if ((float) $slabs->first()['lower_bound'] > 0) {
            return back()->withErrors(['slabs' => 'The first tax slab must start at a lower bound of 0.'])->withInput();
        }
        $bounds = $slabs->pluck('lower_bound')->map(fn ($b) => (float) $b);
        if ($bounds->count() !== $bounds->unique()->count()) {
            return back()->withErrors(['slabs' => 'Tax slab lower bounds must be unique.'])->withInput();
        }

        DB::transaction(function () use ($validated, $slabs, $tenant) {
            PayrollSetting::updateOrCreate(
                ['company_id' => $tenant->id()],
                [
                    'eobi_wage_base' => $validated['eobi_wage_base'],
                    'eobi_employee_rate' => $validated['eobi_employee_rate'],
                    'eobi_employer_rate' => $validated['eobi_employer_rate'],
                    'pf_rate' => $validated['pf_rate'],
                ],
            );

            PayrollTaxSlab::where('company_id', $tenant->id())->delete();
            foreach ($slabs as $slab) {
                PayrollTaxSlab::create([
                    'company_id' => $tenant->id(),
                    'lower_bound' => $slab['lower_bound'],
                    'base_tax' => $slab['base_tax'],
                    'rate' => $slab['rate'],
                ]);
            }
        });

        return back()->with('success', 'Payroll configuration saved. It applies to payroll runs created from now on.');
    }
}
