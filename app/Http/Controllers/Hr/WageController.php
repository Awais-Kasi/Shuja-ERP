<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\WageWorker;
use App\Payroll\WageException;
use App\Payroll\WageService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WageController extends Controller
{
    public function index(): Response
    {
        $workers = WageWorker::query()
            ->withSum('entries as earned', 'amount')
            ->withSum('payments as paid', 'amount')
            ->orderBy('code')
            ->get()
            ->map(fn (WageWorker $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
                'phone' => $w->phone,
                'rate_per_kg' => $w->rate_per_kg !== null ? (float) $w->rate_per_kg : null,
                'is_active' => $w->is_active,
                'balance' => round((float) $w->earned - (float) $w->paid, 2),
            ]);

        return Inertia::render('hr/wages/index', [
            'workers' => $workers,
            'totalOwed' => round($workers->sum('balance'), 2),
        ]);
    }

    public function storeWorker(Request $request, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('wage_workers', 'code')->where('company_id', $tenant->id())],
            'name' => ['required', 'string', 'max:255'],
            'cnic' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'rate_per_kg' => ['nullable', 'numeric', 'gte:0'],
        ]);

        WageWorker::create($validated);

        return back()->with('success', "Worker {$validated['code']} added.");
    }

    public function show(WageWorker $worker): Response
    {
        $worker->load(['entries', 'payments']);

        // Merge work + payments into one chronological wallet ledger with a running balance.
        $items = collect();
        foreach ($worker->entries as $e) {
            $items->push([
                'date' => $e->entry_date->toDateString(),
                'ts' => (string) $e->created_at,
                'number' => $e->number,
                'type' => 'work',
                'detail' => ($e->description ?: 'Work').' — '.rtrim(rtrim(number_format((float) $e->quantity, 2), '0'), '.').' × '.rtrim(rtrim(number_format((float) $e->rate, 2), '0'), '.'),
                'earned' => (float) $e->amount,
                'paid' => 0.0,
            ]);
        }
        foreach ($worker->payments as $p) {
            $items->push([
                'date' => $p->payment_date->toDateString(),
                'ts' => (string) $p->created_at,
                'number' => $p->number,
                'type' => 'payment',
                'detail' => $p->reference ?: 'Payment',
                'earned' => 0.0,
                'paid' => (float) $p->amount,
            ]);
        }

        $balance = 0.0;
        $rows = $items->sortBy([['date', 'asc'], ['ts', 'asc']])->values()->map(function ($r) use (&$balance) {
            $balance = round($balance + $r['earned'] - $r['paid'], 2);

            return [
                'date' => $r['date'],
                'number' => $r['number'],
                'type' => $r['type'],
                'detail' => $r['detail'],
                'earned' => $r['earned'],
                'paid' => $r['paid'],
                'balance' => $balance,
            ];
        });

        return Inertia::render('hr/wages/show', [
            'worker' => [
                'id' => $worker->id,
                'code' => $worker->code,
                'name' => $worker->name,
                'phone' => $worker->phone,
                'cnic' => $worker->cnic,
                'rate_per_kg' => $worker->rate_per_kg !== null ? (float) $worker->rate_per_kg : null,
            ],
            'rows' => $rows,
            'totalEarned' => round((float) $worker->entries->sum('amount'), 2),
            'totalPaid' => round((float) $worker->payments->sum('amount'), 2),
            'balance' => $balance,
            'today' => now()->toDateString(),
            'accounts' => Account::whereIn('control_type', ['cash', 'bank'])->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function recordWork(Request $request, WageWorker $worker, WageService $service): RedirectResponse
    {
        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'rate' => ['required', 'numeric', 'gte:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $service->recordWork($worker, $validated);
        } catch (WageException|PostingException $e) {
            return back()->withErrors(['work' => $e->getMessage()]);
        }

        return back()->with('success', 'Work recorded.');
    }

    public function pay(Request $request, WageWorker $worker, WageService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $service->pay($worker, $validated);
        } catch (WageException|PostingException $e) {
            return back()->withErrors(['pay' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment recorded.');
    }
}
