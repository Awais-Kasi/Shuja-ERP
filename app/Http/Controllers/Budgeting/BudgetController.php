<?php

namespace App\Http\Controllers\Budgeting;

use App\Budgeting\BudgetException;
use App\Budgeting\BudgetService;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(): Response
    {
        $budgets = Budget::with('fiscalYear:id,name')->withCount('lines')->orderByDesc('id')->get()
            ->map(fn (Budget $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'fiscal_year' => $b->fiscalYear->name,
                'status' => $b->status,
                'lines' => $b->lines_count,
            ]);

        return Inertia::render('budgeting/index', [
            'budgets' => $budgets,
            'fiscalYears' => FiscalYear::orderByDesc('starts_on')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, BudgetService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')->where('company_id', $tenant->id())],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $budget = $service->createBudget((int) $validated['fiscal_year_id'], $validated['name']);
        } catch (BudgetException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('budgeting.show', $budget)->with('success', 'Budget created.');
    }

    public function show(Request $request, Budget $budget, BudgetService $service): Response
    {
        $budget->load('fiscalYear');
        $defaultMonth = $this->currentFiscalMonth($budget->fiscalYear);
        $month = (int) $request->integer('month', $defaultMonth);
        $month = max(1, min(12, $month));

        return Inertia::render('budgeting/show', [
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'fiscal_year' => $budget->fiscalYear->name,
                'status' => $budget->status,
            ],
            'variance' => $service->variance($budget, $month),
            'month' => $month,
            'incomeAccounts' => Account::where('type', 'income')->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'expenseAccounts' => Account::where('type', 'expense')->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeLine(Request $request, Budget $budget, BudgetService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                ->whereIn('type', ['income', 'expense'])->where(fn ($q) => $q->where('is_group', false)->where('is_active', true))],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('company_id', $tenant->id())],
            'annual_amount' => ['required', 'numeric', 'gte:0'],
        ]);

        try {
            $service->setLine($budget, (int) $validated['account_id'], $validated['cost_center_id'] ?? null, (float) $validated['annual_amount']);
        } catch (BudgetException $e) {
            return back()->withErrors(['account_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Budget line saved.');
    }

    public function destroyLine(Budget $budget, int $line, BudgetService $service): RedirectResponse
    {
        $service->removeLine($budget, $line);

        return back()->with('success', 'Budget line removed.');
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $budget->lines()->delete();
        $budget->delete();

        return redirect()->route('budgeting.index')->with('success', 'Budget deleted.');
    }

    /** Position of today's month within the fiscal year (1..12). */
    private function currentFiscalMonth(FiscalYear $fy): int
    {
        $now = Carbon::now();
        if ($now->lt($fy->starts_on)) {
            return 1;
        }
        if ($now->gt($fy->ends_on)) {
            return 12;
        }

        return max(1, min(12, $fy->starts_on->diffInMonths($now) + 1));
    }
}
