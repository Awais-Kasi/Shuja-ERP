<?php

namespace App\Http\Controllers\Consignment;

use App\Consignment\ConsignmentException;
use App\Consignment\ConsignmentTripService;
use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\ConsignmentTrip;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConsignmentTripController extends Controller
{
    public function index(): Response
    {
        $trips = ConsignmentTrip::query()
            ->with(['item:id,code,name', 'warehouse:id,code,name'])
            ->orderByDesc('trip_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (ConsignmentTrip $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'date' => $t->trip_date->toDateString(),
                'vehicle_no' => $t->vehicle_no,
                'item' => $t->item?->code,
                'route' => trim(($t->origin ?? '').($t->destination ? ' → '.$t->destination : '')) ?: null,
                'quantity' => (float) $t->quantity,
                'total_cost' => (float) $t->total_cost,
                'sale_amount' => $t->sale_amount !== null ? (float) $t->sale_amount : null,
                'profit' => $t->profit !== null ? (float) $t->profit : null,
                'status' => $t->status,
            ]);

        return Inertia::render('consignment/trips/index', ['trips' => $trips]);
    }

    public function create(): Response
    {
        return Inertia::render('consignment/trips/create', $this->formData());
    }

    public function store(Request $request, ConsignmentTripService $service, TenantManager $tenant): RedirectResponse
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $tenant->id());
        $validated = $request->validate([
            'vehicle_no' => ['nullable', 'string', 'max:100'],
            'source' => ['required', Rule::in(['purchase', 'manufacture'])],
            'item_id' => ['required', 'integer', $scoped('items')],
            'warehouse_id' => ['required', 'integer', $scoped('warehouses')],
            'supplier_id' => ['nullable', 'integer', $scoped('suppliers')],
            'customer_id' => ['nullable', 'integer', $scoped('customers')],
            'origin' => ['nullable', 'string', 'max:150'],
            'destination' => ['nullable', 'string', 'max:150'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'packages' => ['nullable', 'integer', 'gte:0'],
            'goods_rate' => ['nullable', 'numeric', 'gte:0'],
            'goods_credit_account_id' => ['nullable', 'integer', $scoped('accounts')],
            'sale_account_id' => ['nullable', 'integer', $scoped('accounts')],
            'trip_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:500'],
            'steps' => ['array'],
            'steps.*.type' => ['required', 'string', 'max:50'],
            'steps.*.label' => ['nullable', 'string', 'max:150'],
            'steps.*.location' => ['nullable', 'string', 'max:150'],
            'steps.*.vehicle_no' => ['nullable', 'string', 'max:100'],
            'steps.*.basis' => ['required', Rule::in(['per_bag', 'per_kg', 'flat'])],
            'steps.*.rate' => ['required', 'numeric', 'gte:0'],
            'steps.*.credit_account_id' => ['nullable', 'integer', $scoped('accounts')],
        ]);

        if ($validated['source'] === 'purchase' && (float) ($validated['goods_rate'] ?? 0) <= 0) {
            return back()->withErrors(['goods_rate' => 'Enter the purchase rate for the goods.'])->withInput();
        }

        $trip = $service->create($validated);

        return redirect()->route('consignment.trips.show', $trip)
            ->with('success', "Trip {$trip->number} created. Review the journey, then post it to capitalise the costs.");
    }

    public function post(ConsignmentTrip $trip, ConsignmentTripService $service): RedirectResponse
    {
        try {
            $service->post($trip);
        } catch (ConsignmentException|InventoryException|PostingException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return redirect()->route('consignment.trips.show', $trip)
            ->with('success', "Trip {$trip->number} posted. The journey costs are now capitalised onto the goods.");
    }

    public function settle(Request $request, ConsignmentTrip $trip, ConsignmentTripService $service, TenantManager $tenant): RedirectResponse
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $tenant->id());
        $validated = $request->validate([
            'sale_amount' => ['required', 'numeric', 'gte:0'],
            'customer_id' => ['nullable', 'integer', $scoped('customers')],
            'sale_account_id' => ['nullable', 'integer', $scoped('accounts')],
            'settlement_date' => ['required', 'date'],
        ]);

        try {
            $service->settle($trip, $validated);
        } catch (ConsignmentException|InventoryException|PostingException $e) {
            return back()->withErrors(['settlement' => $e->getMessage()]);
        }

        $trip->refresh();
        $pl = (float) $trip->profit >= 0 ? 'profit' : 'loss';

        return redirect()->route('consignment.trips.show', $trip)
            ->with('success', "Trip {$trip->number} settled — ".ucfirst($pl).' of '.number_format(abs((float) $trip->profit), 2).'.');
    }

    public function show(ConsignmentTrip $trip): Response
    {
        $trip->load([
            'item:id,code,name', 'warehouse:id,code,name',
            'supplier:id,code,name', 'customer:id,code,name', 'steps',
        ]);

        return Inertia::render('consignment/trips/show', [
            'trip' => [
                'id' => $trip->id,
                'number' => $trip->number,
                'date' => $trip->trip_date->toDateString(),
                'vehicle_no' => $trip->vehicle_no,
                'source' => $trip->source,
                'item' => $trip->item ? $trip->item->code.' — '.$trip->item->name : null,
                'warehouse' => $trip->warehouse ? $trip->warehouse->code.' — '.$trip->warehouse->name : null,
                'supplier' => $trip->supplier ? $trip->supplier->code.' — '.$trip->supplier->name : null,
                'customer' => $trip->customer ? $trip->customer->code.' — '.$trip->customer->name : null,
                'origin' => $trip->origin,
                'destination' => $trip->destination,
                'quantity' => (float) $trip->quantity,
                'packages' => (int) $trip->packages,
                'goods_rate' => (float) $trip->goods_rate,
                'goods_cost' => (float) $trip->goods_cost,
                'logistics_cost' => (float) $trip->logistics_cost,
                'total_cost' => (float) $trip->total_cost,
                'cost_per_unit' => (float) $trip->quantity > 0 ? round((float) $trip->total_cost / (float) $trip->quantity, 4) : 0,
                'sale_amount' => $trip->sale_amount !== null ? (float) $trip->sale_amount : null,
                'cogs_total' => $trip->cogs_total !== null ? (float) $trip->cogs_total : null,
                'profit' => $trip->profit !== null ? (float) $trip->profit : null,
                'status' => $trip->status,
                'memo' => $trip->memo,
                'journal_id' => $trip->journal_id,
                'settlement_journal_id' => $trip->settlement_journal_id,
                'can_post' => $trip->isDraft(),
                'can_settle' => $trip->isPosted(),
                'steps' => $trip->steps->map(fn ($s) => [
                    'sequence' => $s->sequence,
                    'type' => $s->type,
                    'label' => $s->label,
                    'location' => $s->location,
                    'vehicle_no' => $s->vehicle_no,
                    'basis' => $s->basis,
                    'rate' => (float) $s->rate,
                    'amount' => (float) $s->amount,
                    'status' => $s->status,
                ]),
            ],
            'customers' => Customer::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'accounts' => $this->postableAccounts(),
        ]);
    }

    /** Dropdown data shared by the create screen. */
    private function formData(): array
    {
        return [
            'items' => Item::where('is_active', true)->where('tracks_inventory', true)->orderBy('code')
                ->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'customers' => Customer::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'accounts' => $this->postableAccounts(),
            'today' => now()->toDateString(),
        ];
    }

    /** Postable (non-group, active) accounts the UI offers for credit/sale selects. */
    private function postableAccounts(): \Illuminate\Support\Collection
    {
        return Account::where('is_group', false)->where('is_active', true)
            ->orderBy('code')->get(['id', 'code', 'name', 'control_type', 'type']);
    }
}
