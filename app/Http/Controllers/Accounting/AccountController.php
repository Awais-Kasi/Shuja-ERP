<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(): Response
    {
        $accounts = Account::query()
            ->orderBy('code')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'parent_id' => $a->parent_id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type->value,
                'is_group' => $a->is_group,
                'control_type' => $a->control_type,
                'is_active' => $a->is_active,
            ]);

        return Inertia::render('accounting/accounts', [
            'accounts' => $accounts,
            'groups' => $accounts->where('is_group', true)->values(),
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('accounts', 'code')->where('company_id', $tenant->id()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())],
            'control_type' => ['nullable', 'string', 'max:40'],
            'is_group' => ['boolean'],
        ]);

        Account::create($validated);

        return back()->with('success', "Account {$validated['code']} created.");
    }
}
