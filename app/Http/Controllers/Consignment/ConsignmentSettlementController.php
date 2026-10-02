<?php

namespace App\Http\Controllers\Consignment;

use App\Consignment\ConsignmentException;
use App\Consignment\ConsignmentSettlementService;
use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\ConsignmentDispatch;
use App\Models\ConsignmentSettlement;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConsignmentSettlementController extends Controller
{
    public function index(): Response
    {
        $settlements = ConsignmentSettlement::query()
            ->with(['dispatch:id,number', 'agentCustomer:id,name'])
            ->orderByDesc('settlement_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (ConsignmentSettlement $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'date' => $s->settlement_date->toDateString(),
                'dispatch' => $s->dispatch?->number,
                'agent' => $s->agentCustomer?->name,
                'total' => (float) $s->total,
                'status' => $s->status,
            ]);

        return Inertia::render('consignment/settlements/index', ['settlements' => $settlements]);
    }

    public function create(Request $request): Response
    {
        $dispatch = ConsignmentDispatch::with(['toWarehouse:id,code,name', 'agentCustomer:id,name', 'lines.item:id,code,name'])
            ->findOrFail($request->integer('dispatch_id'));

        return Inertia::render('consignment/settlements/create', [
            'dispatch' => [
                'id' => $dispatch->id,
                'number' => $dispatch->number,
                'consignment_warehouse_id' => $dispatch->to_warehouse_id,
                'agent_customer_id' => $dispatch->agent_customer_id,
                'lines' => $dispatch->lines->map(fn ($l) => [
                    'dispatch_line_id' => $l->id,
                    'item_id' => $l->item_id,
                    'item_label' => $l->item->code.' — '.$l->item->name,
                    'remaining' => (float) $l->quantity - (float) $l->settled_qty - (float) $l->returned_qty,
                ])->values(),
            ],
            'returnWarehouses' => Warehouse::where('is_active', true)->where('type', '!=', 'consignment')->orderBy('code')->get(['id', 'code', 'name']),
            'fundingAccounts' => Account::whereIn('control_type', ['cash', 'bank', 'ap'])->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, ConsignmentSettlementService $service, TenantManager $tenant): RedirectResponse
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $tenant->id());
        $validated = $request->validate([
            'consignment_dispatch_id' => ['required', 'integer', $scoped('consignment_dispatches')],
            'consignment_warehouse_id' => ['required', 'integer', $scoped('warehouses')],
            'agent_customer_id' => ['nullable', 'integer', $scoped('customers')],
            'return_warehouse_id' => ['nullable', 'integer', $scoped('warehouses')],
            'settlement_date' => ['required', 'date'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'return_freight' => ['nullable', 'numeric', 'min:0'],
            'return_freight_account_id' => ['nullable', Rule::requiredIf(fn () => (float) $request->input('return_freight') > 0), 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                ->whereIn('control_type', ['cash', 'bank', 'ap'])->where(fn ($q) => $q->where('is_group', false)->where('is_active', true))],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.consignment_dispatch_line_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.sold_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.returned_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $settlement = DB::transaction(function () use ($validated, $tenant) {
            $settlement = ConsignmentSettlement::create([
                'company_id' => $tenant->id(),
                'consignment_dispatch_id' => $validated['consignment_dispatch_id'],
                'consignment_warehouse_id' => $validated['consignment_warehouse_id'],
                'agent_customer_id' => $validated['agent_customer_id'] ?? null,
                'return_warehouse_id' => $validated['return_warehouse_id'] ?? null,
                'settlement_date' => $validated['settlement_date'],
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'return_freight' => $validated['return_freight'] ?? 0,
                'return_freight_account_id' => $validated['return_freight_account_id'] ?? null,
                'status' => 'draft',
            ]);
            foreach ($validated['lines'] as $line) {
                if (($line['sold_qty'] ?? 0) <= 0 && ($line['returned_qty'] ?? 0) <= 0) {
                    continue;
                }
                $settlement->lines()->create([
                    'company_id' => $tenant->id(),
                    'consignment_dispatch_line_id' => $line['consignment_dispatch_line_id'] ?? null,
                    'item_id' => $line['item_id'],
                    'sold_qty' => $line['sold_qty'] ?? 0,
                    'rate' => $line['rate'] ?? 0,
                    'returned_qty' => $line['returned_qty'] ?? 0,
                ]);
            }

            return $settlement;
        });

        try {
            $service->post($settlement);
        } catch (ConsignmentException|PostingException $e) {
            $settlement->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('consignment.settlements.show', $settlement)->with('success', "Settlement {$settlement->number} posted.");
    }

    public function reverse(ConsignmentSettlement $settlement, ConsignmentSettlementService $service): RedirectResponse
    {
        try {
            $service->reverse($settlement);
        } catch (ConsignmentException|InventoryException|PostingException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return redirect()->route('consignment.settlements.show', $settlement)->with('success', "Settlement {$settlement->number} reversed.");
    }

    public function show(ConsignmentSettlement $settlement): Response
    {
        $settlement->load(['dispatch:id,number', 'agentCustomer:id,code,name', 'consignmentWarehouse:id,code,name', 'lines.item:id,code,name', 'reversalJournal:id,number']);

        $laterExists = ConsignmentSettlement::where('consignment_dispatch_id', $settlement->consignment_dispatch_id)
            ->where('status', 'posted')->where('id', '>', $settlement->id)->exists();

        return Inertia::render('consignment/settlements/show', [
            'settlement' => [
                'id' => $settlement->id,
                'number' => $settlement->number,
                'date' => $settlement->settlement_date->toDateString(),
                'dispatch' => $settlement->dispatch?->number,
                'agent' => $settlement->agentCustomer ? $settlement->agentCustomer->code.' — '.$settlement->agentCustomer->name : null,
                'status' => $settlement->status,
                'subtotal' => (float) $settlement->subtotal,
                'tax_amount' => (float) $settlement->tax_amount,
                'commission_amount' => (float) $settlement->commission_amount,
                'total' => (float) $settlement->total,
                'cogs_total' => (float) $settlement->cogs_total,
                'journal_id' => $settlement->journal_id,
                'reversal_journal_id' => $settlement->reversal_journal_id,
                'reversal_journal' => $settlement->reversalJournal?->number,
                'can_reverse' => $settlement->isPosted() && ! $laterExists,
                'lines' => $settlement->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'sold_qty' => (float) $l->sold_qty,
                    'rate' => (float) $l->rate,
                    'amount' => (float) $l->amount,
                    'returned_qty' => (float) $l->returned_qty,
                ]),
            ],
        ]);
    }
}
