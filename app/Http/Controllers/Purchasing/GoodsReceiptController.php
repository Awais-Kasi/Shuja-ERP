<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Inventory\InventoryException;
use App\Ledger\PostingException;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Purchasing\GoodsReceiptService;
use App\Purchasing\PurchasingException;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptController extends Controller
{
    public function index(): Response
    {
        $receipts = GoodsReceipt::query()
            ->with(['supplier:id,code,name', 'warehouse:id,code'])
            ->orderByDesc('receipt_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (GoodsReceipt $g) => [
                'id' => $g->id,
                'number' => $g->number,
                'date' => $g->receipt_date->toDateString(),
                'supplier' => $g->supplier->name,
                'warehouse' => $g->warehouse->code,
                'status' => $g->status,
                'billed' => $g->billed,
                'total_value' => (float) $g->total_value,
            ]);

        return Inertia::render('purchase/receipts/index', ['receipts' => $receipts]);
    }

    public function create(Request $request): Response
    {
        $prefill = null;
        $poId = $request->integer('purchase_order_id') ?: null;
        if ($poId) {
            $po = PurchaseOrder::with('lines.item:id,code,name')->find($poId);
            if ($po) {
                $prefill = [
                    'purchase_order_id' => $po->id,
                    'number' => $po->number,
                    'supplier_id' => $po->supplier_id,
                    'warehouse_id' => $po->warehouse_id,
                    'lines' => $po->lines
                        ->filter(fn ($l) => (float) $l->received_qty < (float) $l->quantity)
                        ->map(fn ($l) => [
                            'purchase_order_line_id' => $l->id,
                            'item_id' => $l->item_id,
                            'item_label' => $l->item->code.' — '.$l->item->name,
                            'quantity' => (float) $l->quantity - (float) $l->received_qty,
                            'rate' => (float) $l->rate,
                        ])->values(),
                ];
            }
        }

        return Inertia::render('purchase/receipts/create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::where('is_active', true)->where('tracks_inventory', true)->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
            'openOrders' => PurchaseOrder::whereIn('status', ['confirmed'])->with('supplier:id,name')->orderByDesc('id')
                ->get(['id', 'number', 'supplier_id'])->map(fn ($o) => ['id' => $o->id, 'label' => $o->number.' · '.$o->supplier->name]),
            'prefill' => $prefill,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, GoodsReceiptService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'receipt_date' => ['required', 'date'],
            'supplier_dn_no' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $grn = DB::transaction(function () use ($validated, $tenant) {
            $grn = GoodsReceipt::create([
                'company_id' => $tenant->id(),
                'supplier_id' => $validated['supplier_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'receipt_date' => $validated['receipt_date'],
                'supplier_dn_no' => $validated['supplier_dn_no'] ?? null,
                'memo' => $validated['memo'] ?? null,
                'status' => 'draft',
            ]);

            foreach ($validated['lines'] as $line) {
                $grn->lines()->create([
                    'company_id' => $tenant->id(),
                    'item_id' => $line['item_id'],
                    'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'amount' => round($line['quantity'] * $line['rate'], 4),
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $grn;
        });

        try {
            $service->post($grn);
        } catch (PurchasingException|InventoryException|PostingException $e) {
            $grn->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('purchase.receipts.show', $grn)->with('success', "Goods receipt {$grn->number} posted.");
    }

    public function show(GoodsReceipt $receipt): Response
    {
        $receipt->load(['supplier:id,code,name', 'warehouse:id,code,name', 'lines.item:id,code,name']);

        return Inertia::render('purchase/receipts/show', [
            'receipt' => [
                'id' => $receipt->id,
                'number' => $receipt->number,
                'date' => $receipt->receipt_date->toDateString(),
                'supplier' => $receipt->supplier->code.' — '.$receipt->supplier->name,
                'warehouse' => $receipt->warehouse->code.' — '.$receipt->warehouse->name,
                'status' => $receipt->status,
                'billed' => $receipt->billed,
                'memo' => $receipt->memo,
                'journal_id' => $receipt->journal_id,
                'total_value' => (float) $receipt->total_value,
                'lines' => $receipt->lines->map(fn ($l) => [
                    'item' => $l->item->code.' — '.$l->item->name,
                    'quantity' => (float) $l->quantity,
                    'rate' => (float) $l->rate,
                    'amount' => (float) $l->amount,
                ]),
            ],
        ]);
    }
}
