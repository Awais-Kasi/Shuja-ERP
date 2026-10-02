<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::withCount('users')->with('permissions:id,name')->orderBy('name')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id, 'name' => $r->name, 'slug' => $r->slug, 'description' => $r->description,
                'is_system' => (bool) $r->is_system, 'users' => $r->users_count,
                'permissions' => $r->permissions->pluck('name')->all(),
            ]);

        // The permission catalogue grouped for the editor.
        $groups = Permission::orderBy('group')->orderBy('name')->get(['name', 'label', 'group'])
            ->groupBy('group')->map(fn ($rows, $group) => [
                'group' => $group,
                'permissions' => $rows->map(fn ($p) => ['name' => $p->name, 'label' => $p->label])->values(),
            ])->values();

        return Inertia::render('admin/roles/index', ['roles' => $roles, 'catalogue' => $groups]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $slug = $this->uniqueSlug($validated['name'], $tenant->id());
        $role = Role::create([
            'company_id' => $tenant->id(), 'name' => $validated['name'], 'slug' => $slug,
            'description' => $validated['description'] ?? null, 'is_system' => false,
        ]);
        $role->permissions()->sync($this->permissionIds($validated['permissions'] ?? []));

        return back()->with('success', "Role {$role->name} created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role->update(['name' => $validated['name'], 'description' => $validated['description'] ?? null]);
        $role->permissions()->sync($this->permissionIds($validated['permissions'] ?? []));

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->withErrors(['role' => 'System roles cannot be deleted.']);
        }
        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'Reassign users off this role before deleting it.']);
        }
        $role->permissions()->detach();
        $role->delete();

        return back()->with('success', 'Role deleted.');
    }

    /** @param array<int, string> $names */
    private function permissionIds(array $names): array
    {
        return Permission::whereIn('name', $names)->pluck('id')->all();
    }

    private function uniqueSlug(string $name, int $companyId): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 1;
        while (Role::where('company_id', $companyId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
