<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(): Response
    {
        $warehouses = Warehouse::query()
            ->with('costCenter:id,name')
            ->orderBy('code')
            ->get()
            ->map(fn (Warehouse $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
                'type' => $w->type,
                'cost_center' => $w->costCenter?->name,
                'is_active' => $w->is_active,
            ]);

        return Inertia::render('inventory/warehouses', [
            'warehouses' => $warehouses,
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('warehouses', 'code')->where('company_id', $tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['warehouse', 'transit', 'production', 'consignment'])],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('company_id', $tenant->id())],
        ]);

        Warehouse::create($validated);

        return back()->with('success', "Warehouse {$validated['code']} created.");
    }
}
