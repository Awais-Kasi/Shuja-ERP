<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Item;
use App\Models\Uom;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function index(TenantManager $tenant): Response
    {
        $items = Item::query()
            ->with(['uom:id,code', 'inventoryAccount:id,code'])
            ->withSum('balances as qty', 'quantity')
            ->withSum('balances as stock_value', 'value')
            ->orderBy('code')
            ->get()
            ->map(fn (Item $i) => [
                'id' => $i->id,
                'code' => $i->code,
                'name' => $i->name,
                'type' => $i->type,
                'uom' => $i->uom?->code,
                'valuation' => $i->valuationMethod()->label(),
                'account' => $i->inventoryAccount?->code,
                'qty' => (float) $i->qty,
                'value' => (float) $i->stock_value,
                'is_active' => $i->is_active,
            ]);

        return Inertia::render('inventory/items', [
            'items' => $items,
            'uoms' => Uom::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'accounts' => Account::where('is_group', false)->where('type', 'asset')->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name]),
            'defaultValuation' => $tenant->get()?->default_valuation_method,
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('items', 'code')->where('company_id', $tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['stock', 'raw_material', 'finished_good', 'consumable', 'service'])],
            'uom_id' => ['nullable', 'integer', Rule::exists('uoms', 'id')->where('company_id', $tenant->id())],
            'valuation_method' => ['nullable', Rule::in(['fifo', 'weighted_average'])],
            'inventory_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())],
        ]);

        Item::create($validated);

        return back()->with('success', "Item {$validated['code']} created.");
    }
}
