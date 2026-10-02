<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Ledger\PostingException;
use App\Manufacturing\ManufacturingException;
use App\Manufacturing\ProductionService;
use App\Models\Account;
use App\Models\Bom;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function index(): Response
    {
        $orders = WorkOrder::query()
            ->with('item:id,code,name')
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (WorkOrder $w) => [
                'id' => $w->id,
                'number' => $w->number,
                'date' => $w->order_date->toDateString(),
                'item' => $w->item->code.' — '.$w->item->name,
                'quantity' => (float) $w->quantity,
                'status' => $w->status,
                'produced_cost' => (float) $w->produced_cost,
            ]);

        return Inertia::render('manufacturing/work-orders/index', ['orders' => $orders]);
    }

    public function create(): Response
    {
        return Inertia::render('manufacturing/work-orders/create', [
            'boms' => Bom::where('is_active', true)->with('item:id,code,name')->orderBy('code')->get()
                ->map(fn ($b) => ['id' => $b->id, 'label' => $b->code.' — '.$b->item->name, 'output_qty' => (float) $b->output_qty]),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'bom_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'source_warehouse_id' => ['required', 'integer'],
            'target_warehouse_id' => ['required', 'integer'],
            'order_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:500'],
        ]);

        $bom = Bom::with('lines')->findOrFail($validated['bom_id']);
        $factor = (float) $validated['quantity'] / max((float) $bom->output_qty, 0.0001);

        $order = DB::transaction(function () use ($validated, $bom, $factor, $tenant) {
            $order = WorkOrder::create([
                'company_id' => $tenant->id(),
                'bom_id' => $bom->id,
                'item_id' => $bom->item_id,
                'source_warehouse_id' => $validated['source_warehouse_id'],
                'target_warehouse_id' => $validated['target_warehouse_id'],
                'number' => $this->allocateNumber($tenant),
                'order_date' => $validated['order_date'],
                'quantity' => $validated['quantity'],
                'status' => 'draft',
                'memo' => $validated['memo'] ?? null,
            ]);

            foreach ($bom->lines as $line) {
                $order->lines()->create([
                    'company_id' => $tenant->id(),
                    'component_item_id' => $line->component_item_id,
                    'quantity' => round((float) $line->quantity * $factor, 4),
                ]);
            }

            return $order;
        });

        return redirect()->route('manufacturing.work-orders.show', $order)->with('success', "Work order {$order->number} created.");
    }

    public function show(WorkOrder $workOrder): Response
    {
        $workOrder->load(['item:id,code,name', 'sourceWarehouse:id,code,name', 'targetWarehouse:id,code,name', 'lines.componentItem:id,code,name']);

        return Inertia::render('manufacturing/work-orders/show', [
            'order' => [
                'id' => $workOrder->id,
                'number' => $workOrder->number,
                'date' => $workOrder->order_date->toDateString(),
                'item' => $workOrder->item->code.' — '.$workOrder->item->name,
                'quantity' => (float) $workOrder->quantity,
                'source_warehouse' => $workOrder->sourceWarehouse->code.' — '.$workOrder->sourceWarehouse->name,
                'target_warehouse' => $workOrder->targetWarehouse->code.' — '.$workOrder->targetWarehouse->name,
                'status' => $workOrder->status,
                'material_cost' => (float) $workOrder->material_cost,
                'overhead_cost' => (float) $workOrder->overhead_cost,
                'produced_cost' => (float) $workOrder->produced_cost,
                'unit_cost' => $workOrder->produced_cost && $workOrder->quantity ? round((float) $workOrder->produced_cost / (float) $workOrder->quantity, 4) : 0,
                'issue_journal_id' => $workOrder->issue_journal_id,
                'completion_journal_id' => $workOrder->completion_journal_id,
                'lines' => $workOrder->lines->map(fn ($l) => [
                    'component' => $l->componentItem->code.' — '.$l->componentItem->name,
                    'quantity' => (float) $l->quantity,
                    'issued_qty' => (float) $l->issued_qty,
                ]),
            ],
        ]);
    }

    public function issue(WorkOrder $workOrder, ProductionService $service): RedirectResponse
    {
        try {
            $service->issueMaterials($workOrder);
        } catch (ManufacturingException|InventoryException|PostingException $e) {
            return back()->withErrors(['production' => $e->getMessage()]);
        }

        return back()->with('success', 'Materials issued to production.');
    }

    public function complete(Request $request, WorkOrder $workOrder, ProductionService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'overhead' => ['nullable', 'numeric', 'min:0'],
            'overhead_account_id' => ['nullable', 'integer'],
        ]);

        try {
            $service->complete($workOrder, (float) ($validated['overhead'] ?? 0), $validated['overhead_account_id'] ?? null);
        } catch (ManufacturingException|InventoryException|PostingException $e) {
            return back()->withErrors(['production' => $e->getMessage()]);
        }

        return back()->with('success', "Work order {$workOrder->number} completed.");
    }

    private function allocateNumber(TenantManager $tenant): string
    {
        \App\Models\NumberSequence::firstOrCreate(
            ['company_id' => $tenant->id(), 'key' => 'work_order'],
            ['prefix' => 'WO-', 'padding' => 5, 'next_number' => 1],
        );

        return \App\Models\NumberSequence::next('work_order');
    }
}
