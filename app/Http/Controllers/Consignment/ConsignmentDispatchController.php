<?php

namespace App\Http\Controllers\Consignment;

use App\Consignment\ConsignmentDispatchService;
use App\Consignment\ConsignmentException;
use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Ledger\PostingException;
use App\Models\ConsignmentDispatch;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConsignmentDispatchController extends Controller
{
    public function index(): Response
    {
        $dispatches = ConsignmentDispatch::query()
            ->with(['toWarehouse:id,code,name', 'agentCustomer:id,name'])
            ->withCount('lines')
            ->orderByDesc('dispatch_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (ConsignmentDispatch $d) => [
                'id' => $d->id,
                'number' => $d->number,
                'date' => $d->dispatch_date->toDateString(),
                'to' => $d->toWarehouse->code,
                'agent' => $d->agentCustomer?->name,
                'status' => $d->status,
                'lines_count' => $d->lines_count,
            ]);

        return Inertia::render('consignment/dispatches/index', ['dispatches' => $dispatches]);
    }

    public function create(): Response
    {
        return Inertia::render('consignment/dispatches/create', [
            'sources' => Warehouse::where('is_active', true)->where('type', '!=', 'consignment')->orderBy('code')->get(['id', 'code', 'name']),
            'consignmentWarehouses' => Warehouse::where('is_active', true)->where('type', 'consignment')->orderBy('code')->get(['id', 'code', 'name']),
            'agents' => Customer::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('is_active', true)->where('tracks_inventory', true)->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, ConsignmentDispatchService $service, TenantManager $tenant): RedirectResponse
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $tenant->id());
        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'different:to_warehouse_id', $scoped('warehouses')],
            'to_warehouse_id' => ['required', 'integer', $scoped('warehouses')],
            'agent_customer_id' => ['nullable', 'integer', $scoped('customers')],
            'commission_percent' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'via_transit' => ['boolean'],
            'dispatch_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', $scoped('items')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $dispatch = DB::transaction(function () use ($validated, $tenant) {
            $dispatch = ConsignmentDispatch::create([
                'company_id' => $tenant->id(),
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'agent_customer_id' => $validated['agent_customer_id'] ?? null,
                'commission_rate' => ($validated['commission_percent'] ?? 0) / 100,
                'via_transit' => $validated['via_transit'] ?? false,
                'dispatch_date' => $validated['dispatch_date'],
                'memo' => $validated['memo'] ?? null,
                'status' => 'draft',
            ]);
            foreach ($validated['lines'] as $line) {
                $dispatch->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $dispatch;
        });

        try {
            $service->post($dispatch);
        } catch (ConsignmentException|InventoryException|PostingException $e) {
            $dispatch->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        $verb = $dispatch->refresh()->status === 'in_transit' ? 'shipped (in transit)' : 'posted';

        return redirect()->route('consignment.dispatches.show', $dispatch)->with('success', "Dispatch {$dispatch->number} {$verb}.");
    }

    public function receive(ConsignmentDispatch $dispatch, ConsignmentDispatchService $service): RedirectResponse
    {
        try {
            $service->receive($dispatch);
        } catch (ConsignmentException|InventoryException|PostingException $e) {
            return back()->withErrors(['receive' => $e->getMessage()]);
        }

        return redirect()->route('consignment.dispatches.show', $dispatch)->with('success', "Dispatch {$dispatch->number} received onto consignment.");
    }

    public function reverse(ConsignmentDispatch $dispatch, ConsignmentDispatchService $service): RedirectResponse
    {
        try {
            $service->reverse($dispatch);
        } catch (ConsignmentException|InventoryException|PostingException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return redirect()->route('consignment.dispatches.show', $dispatch)->with('success', "Dispatch {$dispatch->number} reversed.");
    }

    public function show(ConsignmentDispatch $dispatch): Response
    {
        $dispatch->load([
            'fromWarehouse:id,code,name', 'toWarehouse:id,code,name', 'agentCustomer:id,code,name',
            'lines.item:id,code,name', 'reversalJournal:id,number',
            'expenses' => fn ($q) => $q->orderBy('id'),
            'expenses.lines', 'expenses.reversalJournal:id,number',
        ]);

        $hasPostedSettlement = $dispatch->settlements()->where('status', 'posted')->exists();
        $canReverse = in_array($dispatch->status, ['posted', 'in_transit'], true)
            && ! $dispatch->settlements()->exists()
            && ! $dispatch->expenses()->where('status', 'posted')->exists();
        $canReceive = $dispatch->status === 'in_transit';

        return Inertia::render('consignment/dispatches/show', [
            'dispatch' => [
                'id' => $dispatch->id,
                'number' => $dispatch->number,
                'date' => $dispatch->dispatch_date->toDateString(),
                'from' => $dispatch->fromWarehouse->code.' — '.$dispatch->fromWarehouse->name,
                'to' => $dispatch->toWarehouse->code.' — '.$dispatch->toWarehouse->name,
                'agent' => $dispatch->agentCustomer ? $dispatch->agentCustomer->code.' — '.$dispatch->agentCustomer->name : null,
                'status' => $dispatch->status,
                'memo' => $dispatch->memo,
                'journal_id' => $dispatch->journal_id,
                'reversal_journal_id' => $dispatch->reversal_journal_id,
                'reversal_journal' => $dispatch->reversalJournal?->number,
                'can_reverse' => $canReverse,
                'can_receive' => $canReceive,
                'via_transit' => (bool) $dispatch->via_transit,
                'lines' => $dispatch->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'quantity' => (float) $l->quantity,
                    'dispatch_rate' => (float) $l->dispatch_rate,
                    'dispatch_value' => (float) $l->dispatch_value,
                    'settled_qty' => (float) $l->settled_qty,
                    'returned_qty' => (float) $l->returned_qty,
                    'remaining' => (float) $l->quantity - (float) $l->settled_qty - (float) $l->returned_qty,
                ]),
                'expenses' => $dispatch->expenses->map(fn ($e) => [
                    'id' => $e->id,
                    'number' => $e->number,
                    'date' => $e->expense_date->toDateString(),
                    'amount' => round((float) $e->lines->sum('amount'), 2),
                    'status' => $e->status,
                    'journal_id' => $e->journal_id,
                    'reversal_journal' => $e->reversalJournal?->number,
                    'reversal_journal_id' => $e->reversal_journal_id,
                    'can_reverse' => $e->status === 'posted' && ! $dispatch->isReversed() && ! $hasPostedSettlement,
                ]),
            ],
        ]);
    }
}
