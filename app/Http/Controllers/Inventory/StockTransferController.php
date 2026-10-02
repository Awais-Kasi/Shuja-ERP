<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Inventory\StockTransferService;
use App\Models\Item;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function index(): Response
    {
        $transfers = StockTransfer::query()
            ->with(['fromWarehouse:id,code', 'toWarehouse:id,code'])
            ->withCount('lines')
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (StockTransfer $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'date' => $t->transfer_date->toDateString(),
                'from' => $t->fromWarehouse->code,
                'to' => $t->toWarehouse->code,
                'status' => $t->status,
                'lines_count' => $t->lines_count,
            ]);

        return Inertia::render('inventory/transfers/index', ['transfers' => $transfers]);
    }

    public function create(): Response
    {
        return Inertia::render('inventory/transfers/create', [
            'items' => Item::where('is_active', true)->where('tracks_inventory', true)->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, StockTransferService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'transfer_date' => ['required', 'date'],
            'from_warehouse_id' => ['required', 'integer', 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'integer'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $transfer = DB::transaction(function () use ($validated, $tenant) {
            $transfer = StockTransfer::create([
                'company_id' => $tenant->id(),
                'transfer_date' => $validated['transfer_date'],
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'memo' => $validated['memo'] ?? null,
                'status' => 'draft',
            ]);

            foreach ($validated['lines'] as $line) {
                $transfer->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $transfer;
        });

        try {
            $service->post($transfer);
        } catch (InventoryException $e) {
            $transfer->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('inventory.transfers.show', $transfer)
            ->with('success', "Transfer {$transfer->number} posted.");
    }

    public function show(StockTransfer $transfer): Response
    {
        $transfer->load(['lines.item:id,code,name', 'fromWarehouse:id,code,name', 'toWarehouse:id,code,name']);

        return Inertia::render('inventory/transfers/show', [
            'transfer' => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'date' => $transfer->transfer_date->toDateString(),
                'from' => $transfer->fromWarehouse->code.' — '.$transfer->fromWarehouse->name,
                'to' => $transfer->toWarehouse->code.' — '.$transfer->toWarehouse->name,
                'memo' => $transfer->memo,
                'status' => $transfer->status,
                'lines' => $transfer->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'quantity' => (float) $l->quantity,
                    'description' => $l->description,
                ]),
            ],
        ]);
    }
}
