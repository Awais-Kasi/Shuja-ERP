<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(): Response
    {
        $orders = PurchaseOrder::query()
            ->with('supplier:id,code,name')
            ->withCount('lines')
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'date' => $o->order_date->toDateString(),
                'supplier' => $o->supplier->name,
                'status' => $o->status,
                'subtotal' => (float) $o->subtotal,
                'lines_count' => $o->lines_count,
            ]);

        return Inertia::render('purchase/orders/index', ['orders' => $orders]);
    }

    public function create(): Response
    {
        return Inertia::render('purchase/orders/create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('is_active', true)->where('is_purchasable', true)->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $order = DB::transaction(function () use ($validated, $tenant) {
            $subtotal = collect($validated['lines'])->sum(fn ($l) => round($l['quantity'] * $l['rate'], 4));

            NumberSequence::firstOrCreate(['company_id' => $tenant->id(), 'key' => 'purchase_order'], ['prefix' => 'PO-', 'padding' => 5, 'next_number' => 1]);

            $order = PurchaseOrder::create([
                'company_id' => $tenant->id(),
                'supplier_id' => $validated['supplier_id'],
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'number' => NumberSequence::next('purchase_order'),
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'status' => 'confirmed',
                'subtotal' => $subtotal,
                'memo' => $validated['memo'] ?? null,
            ]);

            foreach ($validated['lines'] as $line) {
                $order->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'amount' => round($line['quantity'] * $line['rate'], 4),
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $order;
        });

        return redirect()->route('purchase.orders.show', $order)->with('success', "Purchase order {$order->number} created.");
    }

    /** Print-ready A4 purchase order (browser Print / Save-PDF, no PDF library). */
    public function print(PurchaseOrder $order, TenantManager $tenant): Response
    {
        $order->load(['supplier', 'warehouse:id,code,name', 'lines.item:id,code,name']);
        $company = $tenant->get();

        return Inertia::render('purchase/orders/print', [
            'company' => [
                'name' => $company?->name,
                'legal_name' => $company?->legal_name,
                'address' => $company?->address,
                'tax_no' => $company?->tax_registration_no,
                'base_currency' => $company?->base_currency ?? 'PKR',
            ],
            'order' => [
                'number' => $order->number,
                'date' => $order->order_date->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
                'status' => $order->status,
                'warehouse' => $order->warehouse ? $order->warehouse->code.' — '.$order->warehouse->name : null,
                'subtotal' => (float) $order->subtotal,
                'memo' => $order->memo,
                'supplier' => [
                    'code' => $order->supplier->code,
                    'name' => $order->supplier->name,
                    'legal_name' => $order->supplier->legal_name,
                    'address' => $order->supplier->address,
                    'tax_no' => $order->supplier->tax_registration_no,
                    'email' => $order->supplier->email,
                    'phone' => $order->supplier->phone,
                ],
                'lines' => $order->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'description' => $l->description,
                    'quantity' => (float) $l->quantity,
                    'rate' => (float) $l->rate,
                    'amount' => (float) $l->amount,
                ]),
            ],
        ]);
    }

    public function show(PurchaseOrder $order): Response
    {
        $order->load(['supplier:id,code,name', 'warehouse:id,code,name', 'lines.item:id,code,name']);

        return Inertia::render('purchase/orders/show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'date' => $order->order_date->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
                'supplier' => $order->supplier->code.' — '.$order->supplier->name,
                'warehouse' => $order->warehouse ? $order->warehouse->code.' — '.$order->warehouse->name : null,
                'status' => $order->status,
                'memo' => $order->memo,
                'subtotal' => (float) $order->subtotal,
                'lines' => $order->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'quantity' => (float) $l->quantity,
                    'rate' => (float) $l->rate,
                    'amount' => (float) $l->amount,
                    'received_qty' => (float) $l->received_qty,
                ]),
            ],
        ]);
    }
}
