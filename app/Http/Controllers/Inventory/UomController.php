<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Uom;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UomController extends Controller
{
    public function index(): Response
    {
        $uoms = Uom::query()->orderBy('code')->get()
            ->map(fn (Uom $u) => [
                'id' => $u->id,
                'code' => $u->code,
                'name' => $u->name,
                'is_active' => $u->is_active,
            ]);

        return Inertia::render('inventory/uoms', ['uoms' => $uoms]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('uoms', 'code')->where('company_id', $tenant->id())],
            'name' => ['required', 'string', 'max:255'],
        ]);

        // company_id is stamped automatically by the BelongsToCompany trait.
        Uom::create($validated);

        return back()->with('success', "Unit {$validated['code']} created.");
    }
}
