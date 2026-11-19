<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Item;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global "Dhoondo" search — one box to find a customer, supplier, item, invoice
 * or bill by name/code/number and jump straight to it. Returns JSON for the
 * sidebar-header search dropdown. Uses LOWER(..) LIKE so it works on any DB.
 */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
        $match = fn ($query, array $cols) => $query->where(function ($w) use ($cols, $needle) {
            foreach ($cols as $col) {
                $w->orWhereRaw('LOWER('.$col.') LIKE ?', [$needle]);
            }
        });

        $results = [];

        foreach ($match(Customer::query(), ['name', 'code'])->orderBy('name')->limit(6)->get(['id', 'code', 'name']) as $c) {
            $results[] = ['type' => 'Customer', 'label' => $c->name, 'sub' => $c->code, 'href' => "/sales/customer-ledger/{$c->id}"];
        }
        foreach ($match(Supplier::query(), ['name', 'code'])->orderBy('name')->limit(6)->get(['id', 'code', 'name']) as $s) {
            $results[] = ['type' => 'Supplier', 'label' => $s->name, 'sub' => $s->code, 'href' => "/purchase/supplier-ledger/{$s->id}"];
        }
        foreach ($match(Item::query(), ['name', 'code'])->orderBy('name')->limit(6)->get(['id', 'code', 'name']) as $i) {
            $results[] = ['type' => 'Item', 'label' => $i->name, 'sub' => $i->code, 'href' => '/inventory/items'];
        }
        foreach ($match(SalesInvoice::query(), ['number'])->orderByDesc('id')->limit(5)->get(['id', 'number']) as $inv) {
            $results[] = ['type' => 'Invoice', 'label' => $inv->number, 'sub' => 'Sale', 'href' => "/sales/invoices/{$inv->id}"];
        }
        foreach ($match(PurchaseBill::query(), ['number', 'supplier_invoice_no'])->orderByDesc('id')->limit(5)->get(['id', 'number']) as $bill) {
            $results[] = ['type' => 'Bill', 'label' => $bill->number, 'sub' => 'Purchase', 'href' => "/purchase/bills/{$bill->id}"];
        }

        return response()->json(['results' => $results]);
    }
}
