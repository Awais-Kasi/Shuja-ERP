<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Inventory\StockAdjustmentService;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function index(): Response
    {
        $adjustments = StockAdjustment::query()
            ->withCount('lines')
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (StockAdjustment $a) => [
                'id' => $a->id,
                'number' => $a->number,
                'date' => $a->adjustment_date->toDateString(),
                'reason' => $a->reason,
                'memo' => $a->memo,
                'status' => $a->status,
                'lines_count' => $a->lines_count,
            ]);

        return Inertia::render('inventory/adjustments/index', ['adjustments' => $adjustments]);
    }

    public function create(): Response
    {
        return Inertia::render('inventory/adjustments/create', [
            'items' => $this->itemOptions(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'accounts' => Account::where('is_group', false)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, StockAdjustmentService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', Rule::in(['opening', 'adjustment', 'damage', 'count'])],
            'offset_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.warehouse_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'not_in:0'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $adjustment = DB::transaction(function () use ($validated, $tenant) {
            $adjustment = StockAdjustment::create([
                'company_id' => $tenant->id(),
                'adjustment_date' => $validated['adjustment_date'],
                'reason' => $validated['reason'],
                'offset_account_id' => $validated['offset_account_id'],
                'memo' => $validated['memo'] ?? null,
                'status' => 'draft',
            ]);

            foreach ($validated['lines'] as $line) {
                $adjustment->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'] ?? 0,
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $adjustment;
        });

        try {
            $service->post($adjustment);
        } catch (InventoryException|PostingException $e) {
            $adjustment->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('inventory.adjustments.show', $adjustment)
            ->with('success', "Adjustment {$adjustment->number} posted.");
    }

    public function show(StockAdjustment $adjustment): Response
    {
        $adjustment->load(['lines.item:id,code,name', 'lines.warehouse:id,code,name', 'offsetAccount:id,code,name']);

        return Inertia::render('inventory/adjustments/show', [
            'adjustment' => [
                'id' => $adjustment->id,
                'number' => $adjustment->number,
                'date' => $adjustment->adjustment_date->toDateString(),
                'reason' => $adjustment->reason,
                'memo' => $adjustment->memo,
                'status' => $adjustment->status,
                'offset_account' => $adjustment->offsetAccount ? $adjustment->offsetAccount->code.' — '.$adjustment->offsetAccount->name : null,
                'journal_id' => $adjustment->journal_id,
                'lines' => $adjustment->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'warehouse' => $l->warehouse->code,
                    'quantity' => (float) $l->quantity,
                    'rate' => (float) $l->rate,
                    'description' => $l->description,
                ]),
            ],
        ]);
    }

    private function itemOptions()
    {
        return Item::query()
            ->where('is_active', true)
            ->where('tracks_inventory', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]);
    }
}
