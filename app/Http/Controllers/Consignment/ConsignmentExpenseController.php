<?php

namespace App\Http\Controllers\Consignment;

use App\Consignment\ConsignmentException;
use App\Consignment\ConsignmentExpenseService;
use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\ConsignmentDispatch;
use App\Models\ConsignmentExpense;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConsignmentExpenseController extends Controller
{
    public function create(Request $request): Response
    {
        $dispatch = ConsignmentDispatch::with('toWarehouse:id,code,name', 'lines.item:id,code,name')
            ->findOrFail($request->integer('dispatch_id'));

        return Inertia::render('consignment/expenses/create', [
            'dispatch' => [
                'id' => $dispatch->id,
                'number' => $dispatch->number,
                'warehouse' => $dispatch->toWarehouse->code.' — '.$dispatch->toWarehouse->name,
                'warehouse_id' => $dispatch->to_warehouse_id,
                'items' => $dispatch->lines->map(fn ($l) => $l->item->code.' — '.$l->item->name),
            ],
            'accounts' => Account::where('is_group', false)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, ConsignmentExpenseService $service, TenantManager $tenant): RedirectResponse
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $tenant->id());
        $validated = $request->validate([
            'consignment_dispatch_id' => ['required', 'integer', $scoped('consignment_dispatches')],
            'warehouse_id' => ['required', 'integer', $scoped('warehouses')],
            'credit_account_id' => ['required', 'integer', $scoped('accounts')],
            'expense_date' => ['required', 'date'],
            'allocation_basis' => ['required', 'in:value,quantity'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.expense_type' => ['required', 'in:freight,transport,loading,labour,other'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.capitalise' => ['boolean'],
        ]);

        $expense = DB::transaction(function () use ($validated, $tenant) {
            $expense = ConsignmentExpense::create([
                'company_id' => $tenant->id(),
                'consignment_dispatch_id' => $validated['consignment_dispatch_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'credit_account_id' => $validated['credit_account_id'],
                'expense_date' => $validated['expense_date'],
                'allocation_basis' => $validated['allocation_basis'],
                'memo' => $validated['memo'] ?? null,
                'status' => 'draft',
            ]);
            foreach ($validated['lines'] as $line) {
                $expense->lines()->create([
                    'company_id' => $tenant->id(),
                    'expense_type' => $line['expense_type'],
                    'amount' => $line['amount'],
                    'capitalise' => $line['capitalise'] ?? true,
                ]);
            }

            return $expense;
        });

        try {
            $service->post($expense);
        } catch (ConsignmentException|PostingException $e) {
            $expense->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        // Land on a page the consignment.expense.manage role can access (dispatches.show is manage-gated).
        return redirect()->route('consignment.expenses.create', ['dispatch_id' => $validated['consignment_dispatch_id']])
            ->with('success', "Expense {$expense->number} capitalised.");
    }

    public function reverse(ConsignmentExpense $expense, ConsignmentExpenseService $service): RedirectResponse
    {
        $dispatchId = $expense->consignment_dispatch_id;
        try {
            $service->reverse($expense);
        } catch (ConsignmentException|InventoryException|PostingException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return redirect()->route('consignment.dispatches.show', $dispatchId)->with('success', "Expense {$expense->number} reversed.");
    }
}
