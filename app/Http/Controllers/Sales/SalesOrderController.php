<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SalesOrderController extends Controller
{
    public function index(): Response
    {
        $orders = SalesOrder::query()
            ->with('customer:id,code,name')
            ->withCount('lines')
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (SalesOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'date' => $o->order_date->toDateString(),
                'customer' => $o->customer->name,
                'status' => $o->status,
                'subtotal' => (float) $o->subtotal,
                'lines_count' => $o->lines_count,
            ]);

        return Inertia::render('sales/orders/index', ['orders' => $orders]);
    }

    public function create(): Response
    {
        return Inertia::render('sales/orders/create', [
            'customers' => Customer::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('is_active', true)->where('is_sellable', true)->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
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
            NumberSequence::firstOrCreate(['company_id' => $tenant->id(), 'key' => 'sales_order'], ['prefix' => 'SO-', 'padding' => 5, 'next_number' => 1]);

            $order = SalesOrder::create([
                'company_id' => $tenant->id(),
                'customer_id' => $validated['customer_id'],
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'number' => NumberSequence::next('sales_order'),
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

        return redirect()->route('sales.orders.show', $order)->with('success', "Sales order {$order->number} created.");
    }

    public function show(SalesOrder $order): Response
    {
        $order->load(['customer:id,code,name', 'warehouse:id,code,name', 'lines.item:id,code,name']);

        return Inertia::render('sales/orders/show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'date' => $order->order_date->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
                'customer' => $order->customer->code.' — '.$order->customer->name,
                'warehouse' => $order->warehouse ? $order->warehouse->code.' — '.$order->warehouse->name : null,
                'status' => $order->status,
                'memo' => $order->memo,
                'subtotal' => (float) $order->subtotal,
                'lines' => $order->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'quantity' => (float) $l->quantity,
                    'rate' => (float) $l->rate,
                    'amount' => (float) $l->amount,
                    'delivered_qty' => (float) $l->delivered_qty,
                ]),
            ],
        ]);
    }
}
