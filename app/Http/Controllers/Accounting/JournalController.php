<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Journal;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    public function index(Request $request): Response
    {
        $journals = Journal::query()
            ->withSum('lines as debit_total', 'base_debit')
            ->withCount('lines')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (Journal $j) => [
                'id' => $j->id,
                'number' => $j->number,
                'entry_date' => $j->entry_date->toDateString(),
                'type' => $j->type,
                'reference' => $j->reference,
                'memo' => $j->memo,
                'status' => $j->status,
                'amount' => (float) $j->debit_total,
                'lines_count' => $j->lines_count,
            ]);

        return Inertia::render('accounting/journals/index', [
            'journals' => $journals,
        ]);
    }

    public function create(TenantManager $tenant): Response
    {
        return Inertia::render('accounting/journals/create', [
            'accounts' => Account::query()
                ->where('is_group', false)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type'])
                ->map(fn (Account $a) => [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                    'type' => $a->type->value,
                ]),
            'costCenters' => CostCenter::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'baseCurrency' => $tenant->get()?->base_currency,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, PostingEngine $engine): RedirectResponse
    {
        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.cost_center_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = array_map(fn (array $l) => new LedgerLine(
            accountId: (int) $l['account_id'],
            debit: $l['debit'] ?? 0,
            credit: $l['credit'] ?? 0,
            costCenterId: $l['cost_center_id'] ?? null,
            description: $l['description'] ?? null,
        ), $validated['lines']);

        try {
            $journal = $engine->post(new LedgerEntry(
                entryDate: $validated['entry_date'],
                lines: $lines,
                type: 'manual',
                reference: $validated['reference'] ?? null,
                memo: $validated['memo'] ?? null,
            ));
        } catch (PostingException $e) {
            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('accounting.journals.show', $journal)
            ->with('success', "Journal {$journal->number} posted.");
    }

    public function show(Journal $journal): Response
    {
        $journal->load(['lines.account', 'lines.costCenter', 'creator', 'reverses']);

        return Inertia::render('accounting/journals/show', [
            'journal' => [
                'id' => $journal->id,
                'number' => $journal->number,
                'entry_date' => $journal->entry_date->toDateString(),
                'type' => $journal->type,
                'reference' => $journal->reference,
                'memo' => $journal->memo,
                'status' => $journal->status,
                'posted_at' => $journal->posted_at?->toDayDateTimeString(),
                'created_by' => $journal->creator?->name,
                'reverses' => $journal->reverses?->number,
                'lines' => $journal->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'account' => $l->account->code.' — '.$l->account->name,
                    'cost_center' => $l->costCenter?->name,
                    'description' => $l->description,
                    'debit' => (float) $l->base_debit,
                    'credit' => (float) $l->base_credit,
                ]),
            ],
        ]);
    }

    public function reverse(Journal $journal, PostingEngine $engine): RedirectResponse
    {
        try {
            $reversal = $engine->reverse($journal);
        } catch (PostingException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return redirect()
            ->route('accounting.journals.show', $reversal)
            ->with('success', "Reversed as {$reversal->number}.");
    }
}
