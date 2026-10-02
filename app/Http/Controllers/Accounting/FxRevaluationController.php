<?php

namespace App\Http\Controllers\Accounting;

use App\Fx\FxRevaluationException;
use App\Fx\FxRevaluationService;
use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FxRevaluation;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FxRevaluationController extends Controller
{
    public function index(Request $request, FxRevaluationService $service, TenantManager $tenant): Response
    {
        $base = $tenant->get()->base_currency;
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();

        $foreignCurrencies = Currency::where('is_active', true)->where('code', '!=', $base)->orderBy('code')->get(['code', 'name']);

        return Inertia::render('accounting/fx/index', [
            'date' => $date,
            'baseCurrency' => $base,
            'rows' => $service->computeRows($date),
            'foreignCurrencies' => $foreignCurrencies,
            'rates' => ExchangeRate::where('base_code', $base)
                ->orderByDesc('rate_date')->orderBy('quote_code')->limit(30)->get(['id', 'quote_code', 'rate', 'rate_date'])
                ->map(fn ($r) => ['currency' => $r->quote_code, 'rate' => (float) $r->rate, 'date' => $r->rate_date->toDateString()]),
            'revaluations' => FxRevaluation::orderByDesc('revaluation_date')->orderByDesc('id')->limit(24)->get()
                ->map(fn (FxRevaluation $r) => [
                    'id' => $r->id,
                    'date' => $r->revaluation_date->toDateString(),
                    'gain' => (float) $r->total_gain,
                    'loss' => (float) $r->total_loss,
                    'journal_id' => $r->journal_id,
                ]),
            'today' => now()->toDateString(),
        ]);
    }

    public function storeRate(Request $request, TenantManager $tenant): RedirectResponse
    {
        $base = $tenant->get()->base_currency;
        $validated = $request->validate([
            'quote_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true), Rule::notIn([$base])],
            'rate' => ['required', 'numeric', 'gt:0'],
            'rate_date' => ['required', 'date'],
        ]);

        ExchangeRate::updateOrCreate(
            ['company_id' => $tenant->id(), 'base_code' => $base, 'quote_code' => strtoupper($validated['quote_code']), 'rate_date' => $validated['rate_date']],
            ['rate' => $validated['rate'], 'source' => 'manual'],
        );

        return back()->with('success', 'Exchange rate saved.');
    }

    public function run(Request $request, FxRevaluationService $service): RedirectResponse
    {
        $validated = $request->validate(['date' => ['required', 'date']]);

        try {
            $revaluation = $service->run($validated['date']);
        } catch (FxRevaluationException|PostingException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return redirect()->route('accounting.fx.show', $revaluation)->with('success', 'FX revaluation posted.');
    }

    public function show(FxRevaluation $revaluation): Response
    {
        $revaluation->load(['lines.account:id,code,name', 'journal:id,number']);

        return Inertia::render('accounting/fx/show', [
            'revaluation' => [
                'id' => $revaluation->id,
                'date' => $revaluation->revaluation_date->toDateString(),
                'gain' => (float) $revaluation->total_gain,
                'loss' => (float) $revaluation->total_loss,
                'net' => round((float) $revaluation->total_gain - (float) $revaluation->total_loss, 2),
                'journal_id' => $revaluation->journal_id,
                'journal' => $revaluation->journal?->number,
                'lines' => $revaluation->lines->map(fn ($l) => [
                    'account' => $l->account->code.' — '.$l->account->name,
                    'currency' => $l->currency,
                    'foreign_balance' => (float) $l->foreign_balance,
                    'closing_rate' => (float) $l->closing_rate,
                    'carrying_base' => (float) $l->carrying_base,
                    'revalued_base' => (float) $l->revalued_base,
                    'adjustment' => (float) $l->adjustment,
                ]),
            ],
        ]);
    }
}
