<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $employees = Employee::query()
            ->with(['costCenter:id,code,name'])
            ->orderBy('code')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'code' => $e->code,
                'name' => $e->name,
                'designation' => $e->designation,
                'department' => $e->department,
                'employment_type' => $e->employment_type,
                'payment_method' => $e->payment_method,
                'cost_center' => $e->costCenter?->code,
                'gross' => $e->grossEarnings(),
                'is_active' => (bool) $e->is_active,
            ]);

        return Inertia::render('hr/employees/index', [
            'employees' => $employees,
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'salaryAccounts' => Account::where('type', 'expense')->where('is_group', false)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'summary' => [
                'total' => $employees->count(),
                'active' => $employees->where('is_active', true)->count(),
                'monthly_gross' => round($employees->where('is_active', true)->sum('gross'), 2),
            ],
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $data = $this->validated($request, $tenant);

        Employee::create($data + ['company_id' => $tenant->id()]);

        return back()->with('success', "Employee {$data['code']} created.");
    }

    public function update(Request $request, Employee $employee, TenantManager $tenant): RedirectResponse
    {
        $data = $this->validated($request, $tenant, $employee->id);

        $employee->update($data);

        return back()->with('success', "Employee {$employee->code} updated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, TenantManager $tenant, ?int $ignoreId = null): array
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $tenant->id());

        return $request->validate([
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('employees', 'code')->where('company_id', $tenant->id())->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', Rule::in(['salaried', 'wage'])],
            'payment_method' => ['required', Rule::in(['bank', 'cash'])],
            'cost_center_id' => ['nullable', 'integer', $scoped('cost_centers')],
            'salary_expense_account_id' => [
                'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                    ->where('type', 'expense')
                    ->where(fn ($q) => $q->where('is_group', false)),
            ],
            'cnic' => ['nullable', 'string', 'max:20'],
            'eobi_no' => ['nullable', 'string', 'max:40'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'basic_salary' => ['required', 'numeric', 'gte:0'],
            'house_rent' => ['nullable', 'numeric', 'gte:0'],
            'medical' => ['nullable', 'numeric', 'gte:0'],
            'conveyance' => ['nullable', 'numeric', 'gte:0'],
            'other_allowance' => ['nullable', 'numeric', 'gte:0'],
            'date_joined' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);
    }
}
