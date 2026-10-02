<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\RecurringJournal;
use App\Recurring\RecurringJournalException;
use App\Recurring\RecurringJournalService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecurringJournalController extends Controller
{
    public function index(): Response
    {
        $templates = RecurringJournal::withSum('lines as amount', 'debit')->withCount('generated')
            ->orderBy('status')->orderBy('next_run_date')->get()
            ->map(fn (RecurringJournal $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'frequency' => $t->frequency,
                'interval' => $t->interval,
                'next_run_date' => $t->next_run_date->toDateString(),
                'end_date' => $t->end_date?->toDateString(),
                'status' => $t->status,
                'amount' => (float) $t->amount,
                'generated' => $t->generated_count,
            ]);

        return Inertia::render('accounting/recurring/index', [
            'templates' => $templates,
            'today' => now()->toDateString(),
            'dueCount' => RecurringJournal::where('status', 'active')->whereDate('next_run_date', '<=', now()->toDateString())->count(),
        ]);
    }

    public function create(TenantManager $tenant): Response
    {
        return Inertia::render('accounting/recurring/create', [
            'accounts' => Account::where('is_group', false)->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'type'])->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type->value]),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'frequencies' => RecurringJournalService::FREQUENCIES,
            'baseCurrency' => $tenant->get()?->base_currency,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, RecurringJournalService $service): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'frequency' => ['required', Rule::in(RecurringJournalService::FREQUENCIES)],
            'interval' => ['required', 'integer', 'min:1', 'max:60'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.cost_center_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $template = $service->create($validated, $validated['lines']);
        } catch (RecurringJournalException $e) {
            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route('accounting.recurring.show', $template)->with('success', 'Recurring journal created.');
    }

    public function show(RecurringJournal $recurring): Response
    {
        $recurring->load(['lines.account:id,code,name', 'lines.costCenter:id,code,name']);
        $generated = $recurring->generated()->orderByDesc('entry_date')->orderByDesc('id')->limit(50)->get(['id', 'number', 'entry_date'])
            ->map(fn ($j) => ['id' => $j->id, 'number' => $j->number, 'entry_date' => $j->entry_date->toDateString()]);

        return Inertia::render('accounting/recurring/show', [
            'template' => [
                'id' => $recurring->id,
                'name' => $recurring->name,
                'reference' => $recurring->reference,
                'memo' => $recurring->memo,
                'frequency' => $recurring->frequency,
                'interval' => $recurring->interval,
                'start_date' => $recurring->start_date->toDateString(),
                'next_run_date' => $recurring->next_run_date->toDateString(),
                'end_date' => $recurring->end_date?->toDateString(),
                'status' => $recurring->status,
                'last_generated_at' => $recurring->last_generated_at?->toDayDateTimeString(),
                'lines' => $recurring->lines->map(fn ($l) => [
                    'account' => $l->account->code.' — '.$l->account->name,
                    'cost_center' => $l->costCenter?->code,
                    'description' => $l->description,
                    'debit' => (float) $l->debit,
                    'credit' => (float) $l->credit,
                ]),
            ],
            'generated' => $generated,
            'today' => now()->toDateString(),
        ]);
    }

    public function runDue(RecurringJournalService $service): RedirectResponse
    {
        $result = $service->runDue();

        return $this->flash($result);
    }

    public function runOne(RecurringJournal $recurring, RecurringJournalService $service): RedirectResponse
    {
        try {
            $result = $service->runOne($recurring);
        } catch (RecurringJournalException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return $this->flash($result);
    }

    public function pause(RecurringJournal $recurring, RecurringJournalService $service): RedirectResponse
    {
        try {
            $service->pause($recurring);
        } catch (RecurringJournalException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', 'Template paused.');
    }

    public function resume(RecurringJournal $recurring, RecurringJournalService $service): RedirectResponse
    {
        try {
            $service->resume($recurring);
        } catch (RecurringJournalException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', 'Template resumed.');
    }

    public function destroy(RecurringJournal $recurring): RedirectResponse
    {
        $recurring->lines()->delete();
        $recurring->delete();

        return redirect()->route('accounting.recurring.index')->with('success', 'Template deleted.');
    }

    /**
     * @param  array{generated:int, errors:array<int, array{template:string, date:string, message:string}>}  $result
     */
    private function flash(array $result): RedirectResponse
    {
        if ($result['errors']) {
            $first = $result['errors'][0];

            return back()->with('success', "Generated {$result['generated']} journal(s).")
                ->withErrors(['run' => "{$first['template']} ({$first['date']}): {$first['message']}"]);
        }

        return back()->with('success', "Generated {$result['generated']} journal(s).");
    }
}
