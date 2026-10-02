<?php

namespace App\Http\Controllers\Banking;

use App\Banking\BankReconciliationException;
use App\Banking\BankReconciliationService;
use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\JournalLine;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankReconciliationController extends Controller
{
    public function index(): Response
    {
        $accounts = Account::whereIn('control_type', ['bank', 'cash'])
            ->where('is_group', false)->where('is_active', true)
            ->orderBy('code')->get(['id', 'code', 'name']);

        $balances = $this->bookBalances($accounts->pluck('id')->all());

        $reconciliations = BankReconciliation::with(['bankAccount:id,code,name', 'lines:id,bank_reconciliation_id,amount'])
            ->orderByDesc('statement_date')->orderByDesc('id')->get()
            ->map(fn (BankReconciliation $r) => [
                'id' => $r->id,
                'account' => $r->bankAccount->code.' — '.$r->bankAccount->name,
                'statement_date' => $r->statement_date->toDateString(),
                'statement_balance' => (float) $r->statement_balance,
                'reconciled' => $r->reconciledBalance(),
                'difference' => $r->difference(),
                'status' => $r->status,
                'cleared_count' => $r->lines->count(),
            ]);

        return Inertia::render('banking/index', [
            'reconciliations' => $reconciliations,
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->code.' — '.$a->name,
                'book_balance' => round($balances[$a->id] ?? 0, 2),
            ]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, BankReconciliationService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'bank_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                ->whereIn('control_type', ['bank', 'cash'])->where(fn ($q) => $q->where('is_group', false)->where('is_active', true))],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
        ]);

        try {
            $recon = $service->start((int) $validated['bank_account_id'], $validated['statement_date'], (float) $validated['statement_balance']);
        } catch (BankReconciliationException $e) {
            return back()->withErrors(['bank_account_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('banking.show', $recon)->with('success', 'Reconciliation started.');
    }

    public function show(BankReconciliation $reconciliation): Response
    {
        $reconciliation->load(['bankAccount:id,code,name', 'lines']);

        $clearedHere = $reconciliation->lines->keyBy('journal_line_id');
        $reconciledElsewhere = BankReconciliationLine::where('bank_reconciliation_id', '!=', $reconciliation->id)
            ->pluck('journal_line_id')->all();

        $lines = JournalLine::where('account_id', $reconciliation->bank_account_id)
            ->whereHas('journal', fn ($q) => $q->where('status', 'posted'))
            ->when($reconciledElsewhere, fn ($q) => $q->whereNotIn('id', $reconciledElsewhere))
            ->with('journal:id,number,entry_date,type,memo,reference')
            ->get()
            ->map(fn (JournalLine $l) => [
                'id' => $l->id,
                'date' => $l->journal->entry_date->toDateString(),
                'journal_id' => $l->journal_id,
                'journal_number' => $l->journal->number,
                'type' => $l->journal->type,
                'description' => $l->description ?: $l->journal->memo,
                'reference' => $l->journal->reference,
                'amount' => round((float) $l->base_debit - (float) $l->base_credit, 4),
                'cleared' => $clearedHere->has($l->id),
            ])
            ->sortBy([['date', 'asc'], ['id', 'asc']])
            ->values();

        return Inertia::render('banking/show', [
            'reconciliation' => [
                'id' => $reconciliation->id,
                'account' => $reconciliation->bankAccount->code.' — '.$reconciliation->bankAccount->name,
                'statement_date' => $reconciliation->statement_date->toDateString(),
                'opening_balance' => (float) $reconciliation->opening_balance,
                'statement_balance' => (float) $reconciliation->statement_balance,
                'cleared_total' => $reconciliation->clearedTotal(),
                'reconciled' => $reconciliation->reconciledBalance(),
                'difference' => $reconciliation->difference(),
                'status' => $reconciliation->status,
                'book_balance' => round($this->bookBalances([$reconciliation->bank_account_id])[$reconciliation->bank_account_id] ?? 0, 4),
            ],
            'lines' => $lines,
            'contraAccounts' => [
                'expense' => Account::where('type', 'expense')->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
                'income' => Account::where('type', 'income')->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            ],
            'defaults' => [
                'charge_account_id' => Account::where('code', '6700')->value('id'),
                'credit_account_id' => Account::where('code', '4300')->value('id'),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function toggle(Request $request, BankReconciliation $reconciliation, BankReconciliationService $service): RedirectResponse
    {
        $validated = $request->validate(['journal_line_id' => ['required', 'integer']]);

        try {
            $service->toggleCleared($reconciliation, (int) $validated['journal_line_id']);
        } catch (BankReconciliationException $e) {
            return back()->withErrors(['clear' => $e->getMessage()]);
        }

        return back();
    }

    public function adjust(Request $request, BankReconciliation $reconciliation, BankReconciliationService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['charge', 'credit'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'counter_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                ->where(fn ($q) => $q->where('is_group', false)->where('is_active', true))],
            'date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $service->addAdjustment(
                $reconciliation,
                $validated['kind'],
                (float) $validated['amount'],
                (int) $validated['counter_account_id'],
                $validated['date'],
                $validated['memo'] ?? null,
            );
        } catch (BankReconciliationException|PostingException $e) {
            return back()->withErrors(['adjust' => $e->getMessage()]);
        }

        return back()->with('success', 'Adjustment posted.');
    }

    public function complete(BankReconciliation $reconciliation, BankReconciliationService $service): RedirectResponse
    {
        try {
            $service->complete($reconciliation);
        } catch (BankReconciliationException $e) {
            return back()->withErrors(['complete' => $e->getMessage()]);
        }

        return back()->with('success', 'Reconciliation completed.');
    }

    public function reopen(BankReconciliation $reconciliation, BankReconciliationService $service): RedirectResponse
    {
        try {
            $service->reopen($reconciliation);
        } catch (BankReconciliationException $e) {
            return back()->withErrors(['reopen' => $e->getMessage()]);
        }

        return back()->with('success', 'Reconciliation reopened.');
    }

    /**
     * Current GL balance (base_debit − base_credit) per bank account.
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, float>
     */
    private function bookBalances(array $accountIds): array
    {
        if (! $accountIds) {
            return [];
        }

        return JournalLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journal', fn ($q) => $q->where('status', 'posted'))
            ->groupBy('account_id')
            ->select('account_id', DB::raw('SUM(base_debit - base_credit) as bal'))
            ->pluck('bal', 'account_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
