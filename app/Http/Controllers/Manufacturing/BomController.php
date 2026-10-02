<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Item;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BomController extends Controller
{
    public function index(): Response
    {
        $boms = Bom::query()
            ->with('item:id,code,name')
            ->withCount('lines')
            ->orderBy('code')
            ->get()
            ->map(fn (Bom $b) => [
                'id' => $b->id,
                'code' => $b->code,
                'name' => $b->name,
                'output' => $b->item->code.' — '.$b->item->name,
                'output_qty' => (float) $b->output_qty,
                'components' => $b->lines_count,
                'is_active' => $b->is_active,
            ]);

        return Inertia::render('manufacturing/boms/index', ['boms' => $boms]);
    }

    public function create(): Response
    {
        return Inertia::render('manufacturing/boms/create', [
            'items' => Item::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name]),
        ]);
    }

    public function store(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('boms', 'code')->where('company_id', $tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'item_id' => ['required', 'integer'],
            'output_qty' => ['required', 'numeric', 'gt:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.component_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $bom = DB::transaction(function () use ($validated, $tenant) {
            $bom = Bom::create([
                'company_id' => $tenant->id(),
                'item_id' => $validated['item_id'],
                'code' => $validated['code'],
                'name' => $validated['name'],
                'output_qty' => $validated['output_qty'],
            ]);
            foreach ($validated['lines'] as $line) {
                $bom->lines()->create([
                    'company_id' => $tenant->id(),
                    'component_item_id' => $line['component_item_id'],
                    'quantity' => $line['quantity'],
                ]);
            }

            return $bom;
        });

        return redirect()->route('manufacturing.boms.show', $bom)->with('success', "BOM {$bom->code} created.");
    }

    public function show(Bom $bom): Response
    {
        $bom->load(['item:id,code,name', 'lines.componentItem:id,code,name']);

        return Inertia::render('manufacturing/boms/show', [
            'bom' => [
                'id' => $bom->id,
                'code' => $bom->code,
                'name' => $bom->name,
                'output' => $bom->item->code.' — '.$bom->item->name,
                'output_qty' => (float) $bom->output_qty,
                'lines' => $bom->lines->map(fn ($l) => [
                    'component' => $l->componentItem->code.' — '.$l->componentItem->name,
                    'quantity' => (float) $l->quantity,
                ]),
            ],
        ]);
    }
}
