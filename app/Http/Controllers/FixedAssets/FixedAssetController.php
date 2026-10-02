<?php

namespace App\Http\Controllers\FixedAssets;

use App\FixedAssets\FixedAssetException;
use App\FixedAssets\FixedAssetService;
use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetController extends Controller
{
    private const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public function index(): Response
    {
        $assets = FixedAsset::query()->orderBy('code')->get()->map(fn (FixedAsset $a) => [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'category' => $a->category,
            'cost' => (float) $a->cost,
            'accumulated_depreciation' => (float) $a->accumulated_depreciation,
            'book_value' => $a->bookValue(),
            'status' => $a->status,
            'acquisition_date' => $a->acquisition_date->toDateString(),
        ]);

        return Inertia::render('fixedassets/index', [
            'assets' => $assets,
            'assetAccounts' => Account::where('type', 'asset')->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'expenseAccounts' => Account::where('type', 'expense')->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'fundingAccounts' => Account::where('is_group', false)->where('is_active', true)->whereIn('control_type', ['cash', 'bank', 'ap'])->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'summary' => [
                'count' => $assets->where('status', '!=', 'disposed')->count(),
                'cost' => round($assets->where('status', '!=', 'disposed')->sum('cost'), 2),
                'accumulated' => round($assets->where('status', '!=', 'disposed')->sum('accumulated_depreciation'), 2),
                'book_value' => round($assets->where('status', '!=', 'disposed')->sum('book_value'), 2),
            ],
            'defaults' => [
                'asset_account_id' => Account::where('code', '1510')->value('id'),
                'accum_account_id' => Account::where('code', '1520')->value('id'),
                'depreciation_account_id' => Account::where('code', '6600')->value('id'),
            ],
        ]);
    }

    public function store(Request $request, FixedAssetService $service, TenantManager $tenant): RedirectResponse
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $tenant->id());
        // Accounts posted to must be POSTABLE leaf, active accounts of the right type —
        // a group/inactive account would otherwise sail through here and later break the
        // shared depreciation run for the whole company.
        $acct = function (array $types = []) use ($tenant) {
            $rule = Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                ->where(fn ($q) => $q->where('is_group', false)->where('is_active', true));
            if ($types) {
                $rule->whereIn('type', $types);
            }

            return $rule;
        };
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('fixed_assets', 'code')->where('company_id', $tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'asset_account_id' => ['required', 'integer', $acct(['asset'])],
            'accum_account_id' => ['required', 'integer', $acct(['asset'])],
            'depreciation_account_id' => ['required', 'integer', $acct(['expense'])],
            'cost_center_id' => ['nullable', 'integer', $scoped('cost_centers')],
            'funding_account_id' => ['required', 'integer', $acct()],
            'cost' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'gte:0', 'lt:cost'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'acquisition_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $asset = FixedAsset::create([
            'company_id' => $tenant->id(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'category' => $validated['category'] ?? null,
            'asset_account_id' => $validated['asset_account_id'],
            'accum_account_id' => $validated['accum_account_id'],
            'depreciation_account_id' => $validated['depreciation_account_id'],
            'cost_center_id' => $validated['cost_center_id'] ?? null,
            'cost' => $validated['cost'],
            'salvage_value' => $validated['salvage_value'] ?? 0,
            'useful_life_months' => $validated['useful_life_months'],
            'acquisition_date' => $validated['acquisition_date'],
            'memo' => $validated['memo'] ?? null,
            'status' => 'active',
        ]);

        try {
            $service->acquire($asset, (int) $validated['funding_account_id']);
        } catch (FixedAssetException|PostingException $e) {
            $asset->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('assets.show', $asset)->with('success', "Asset {$asset->code} capitalised.");
    }

    public function show(FixedAsset $asset): Response
    {
        $asset->load(['costCenter:id,code,name', 'journal:id,number', 'depreciationExpenseAccount:id,code,name']);
        $history = DepreciationRun::query()
            ->whereHas('lines', fn ($q) => $q->where('fixed_asset_id', $asset->id))
            ->with(['lines' => fn ($q) => $q->where('fixed_asset_id', $asset->id)])
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->get()
            ->map(fn ($r) => [
                'period' => self::MONTHS[$r->period_month].' '.$r->period_year,
                'amount' => (float) ($r->lines->first()?->amount ?? 0),
                'journal_id' => $r->journal_id,
            ]);

        return Inertia::render('fixedassets/show', [
            'asset' => [
                'id' => $asset->id,
                'code' => $asset->code,
                'name' => $asset->name,
                'category' => $asset->category,
                'cost' => (float) $asset->cost,
                'salvage_value' => (float) $asset->salvage_value,
                'useful_life_months' => $asset->useful_life_months,
                'acquisition_date' => $asset->acquisition_date->toDateString(),
                'accumulated_depreciation' => (float) $asset->accumulated_depreciation,
                'book_value' => $asset->bookValue(),
                'monthly_depreciation' => $asset->monthlyDepreciation(),
                'status' => $asset->status,
                'cost_center' => $asset->costCenter?->code,
                'journal_id' => $asset->journal_id,
                'journal' => $asset->journal?->number,
                'disposed_at' => $asset->disposed_at?->toDateString(),
                'disposal_journal_id' => $asset->disposal_journal_id,
            ],
            'history' => $history,
            'cashAccounts' => Account::whereIn('control_type', ['cash', 'bank'])->where('is_group', false)->orderBy('code')->get(['id', 'code', 'name']),
            'today' => now()->toDateString(),
        ]);
    }

    public function dispose(Request $request, FixedAsset $asset, FixedAssetService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'proceeds' => ['required', 'numeric', 'gte:0'],
            'cash_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                ->whereIn('control_type', ['cash', 'bank'])->where(fn ($q) => $q->where('is_group', false)->where('is_active', true))],
            'date' => ['required', 'date'],
        ]);

        try {
            $service->dispose($asset, (float) $validated['proceeds'], $validated['date'], (int) $validated['cash_account_id']);
        } catch (FixedAssetException|PostingException $e) {
            return back()->withErrors(['disposal' => $e->getMessage()]);
        }

        return redirect()->route('assets.show', $asset)->with('success', "Asset {$asset->code} disposed.");
    }

    public function depreciationIndex(): Response
    {
        $now = now();

        return Inertia::render('fixedassets/depreciation', [
            'runs' => DepreciationRun::query()->withCount('lines')->orderByDesc('period_year')->orderByDesc('period_month')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'period' => self::MONTHS[$r->period_month].' '.$r->period_year,
                    'run_date' => $r->run_date->toDateString(),
                    'assets' => $r->lines_count,
                    'total' => (float) $r->total_amount,
                    'journal_id' => $r->journal_id,
                ]),
            'months' => collect(self::MONTHS)->map(fn ($name, $n) => ['value' => $n, 'label' => $name])->values(),
            'defaults' => ['period_year' => (int) $now->year, 'period_month' => (int) $now->month],
            'activeAssets' => FixedAsset::where('status', 'active')->count(),
        ]);
    }

    public function runDepreciation(Request $request, FixedAssetService $service): RedirectResponse
    {
        $validated = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $service->runDepreciation((int) $validated['period_year'], (int) $validated['period_month']);
        } catch (FixedAssetException|PostingException $e) {
            return back()->withErrors(['depreciation' => $e->getMessage()]);
        }

        return back()->with('success', 'Depreciation posted.');
    }
}
