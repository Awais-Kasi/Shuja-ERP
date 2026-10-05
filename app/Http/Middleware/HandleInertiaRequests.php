<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = app(TenantManager::class);
        $current = $tenant->get();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'version' => config('version.number'),
            'auth' => [
                'user' => $user,
                'permissions' => $user ? $user->permissionNames() : [],
                'isSuperAdmin' => (bool) $user?->is_super_admin,
            ],
            'tenant' => [
                'current' => $current ? [
                    'id' => $current->id,
                    'name' => $current->name,
                    'code' => $current->code,
                    'base_currency' => $current->base_currency,
                ] : null,
                'companies' => $user
                    ? $user->companies()->orderBy('name')->get()->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'code' => $c->code,
                        'base_currency' => $c->base_currency,
                    ])->all()
                    : [],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
