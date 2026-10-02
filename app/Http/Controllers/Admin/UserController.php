<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(TenantManager $tenant): Response
    {
        $companyId = $tenant->id();
        $roles = Role::orderBy('name')->get(['id', 'name']);
        $roleNames = $roles->pluck('name', 'id');

        $users = User::whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->with(['companies' => fn ($q) => $q->where('companies.id', $companyId)])
            ->orderBy('name')->get()
            ->map(function (User $u) use ($roleNames) {
                $roleId = $u->companies->first()?->pivot?->role_id;

                return [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                    'role_id' => $roleId, 'role' => $roleId ? ($roleNames[$roleId] ?? '—') : '—',
                    'is_super_admin' => (bool) $u->is_super_admin,
                    'verified' => $u->email_verified_at !== null,
                ];
            });

        return Inertia::render('admin/users/index', ['users' => $users, 'roles' => $roles]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', Password::defaults()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('company_id', $tenant->id())],
        ]);

        $user = User::create([
            'name' => $validated['name'], 'email' => $validated['email'],
            'password' => $validated['password'], 'default_company_id' => $tenant->id(),
            'email_verified_at' => now(),
        ]);
        $user->companies()->syncWithoutDetaching([$tenant->id() => ['role_id' => $validated['role_id'], 'is_default' => true]]);

        return back()->with('success', "User {$user->name} created.");
    }

    public function update(Request $request, User $user, TenantManager $tenant): RedirectResponse
    {
        $this->assertMember($user, $tenant);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('company_id', $tenant->id())],
        ]);

        $user->update(['name' => $validated['name'], 'email' => $validated['email']]);
        $user->companies()->updateExistingPivot($tenant->id(), ['role_id' => $validated['role_id']]);

        return back()->with('success', 'User updated.');
    }

    public function resetPassword(Request $request, User $user, TenantManager $tenant): RedirectResponse
    {
        $this->assertMember($user, $tenant);
        $validated = $request->validate(['password' => ['required', Password::defaults()]]);
        $user->update(['password' => $validated['password']]);

        return back()->with('success', 'Password reset.');
    }

    public function destroy(User $user, TenantManager $tenant): RedirectResponse
    {
        $this->assertMember($user, $tenant);
        if ($user->id === Auth::id()) {
            return back()->withErrors(['user' => 'You cannot remove yourself from the company.']);
        }
        $user->companies()->detach($tenant->id());

        return back()->with('success', 'User removed from company.');
    }

    private function assertMember(User $user, TenantManager $tenant): void
    {
        abort_unless($user->belongsToCompany($tenant->id()), 404);
    }
}
