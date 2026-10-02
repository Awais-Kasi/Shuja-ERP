<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    private function direction(Request $request): string
    {
        return $request->route('direction') === 'pay' ? 'pay' : 'receive';
    }

    public function index(Request $request): Response
    {
        $direction = $this->direction($request);

        $payments = Payment::where('direction', $direction)
            ->with(['party:id,code,name', 'account:id,code,name'])
            ->withCount('allocations')
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate(20)
            ->through(fn (Payment $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'date' => $p->payment_date->toDateString(),
                'party' => $p->party?->name,
                'account' => $p->account->code,
                'amount' => (float) $p->amount,
                'status' => $p->status,
                'allocations' => $p->allocations_count,
            ]);

        return Inertia::render('payments/index', [
            'direction' => $direction,
            'payments' => $payments,
        ]);
    }

    public function create(Request $request, TenantManager $tenant): Response
    {
        $direction = $this->direction($request);
        $receipt = $direction === 'receive';
        $base = $tenant->get()->base_currency;

        $parties = ($receipt ? Customer::query() : Supplier::query())
            ->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);

        $open = $receipt
            ? SalesInvoice::where('status', 'posted')->whereColumn('amount_paid', '<', 'total')
                ->orderBy('invoice_date')->get(['id', 'customer_id as party_id', 'number', 'invoice_date as date', 'currency', 'total', 'amount_paid'])
            : PurchaseBill::where('status', 'posted')->whereColumn('amount_paid', '<', 'total')
                ->orderBy('bill_date')->get(['id', 'supplier_id as party_id', 'number', 'bill_date as date', 'currency', 'total', 'amount_paid']);

        $openDocuments = $open->map(fn ($d) => [
            'id' => $d->id,
            'party_id' => $d->party_id,
            'number' => $d->number,
            'date' => \Illuminate\Support\Carbon::parse($d->date)->toDateString(),
            'currency' => $d->currency ?: $base,
            'total' => (float) $d->total,
            'outstanding' => round((float) $d->total - (float) $d->amount_paid, 4),
        ])->values();

        $rates = [$base => 1.0];
        foreach (ExchangeRate::where('base_code', $base)->orderByDesc('rate_date')->orderByDesc('id')->get(['quote_code', 'rate']) as $r) {
            $rates[$r->quote_code] ??= (float) $r->rate;
        }

        return Inertia::render('payments/create', [
            'direction' => $direction,
            'parties' => $parties,
            'accounts' => Account::whereIn('control_type', ['cash', 'bank'])->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'openDocuments' => $openDocuments,
            'today' => now()->toDateString(),
            'baseCurrency' => $base,
            'currencies' => array_keys($rates),
            'rates' => $rates,
        ]);
    }

    public function store(Request $request, PaymentService $service, TenantManager $tenant): RedirectResponse
    {
        $direction = $this->direction($request);
        $receipt = $direction === 'receive';
        $partyTable = $receipt ? 'customers' : 'suppliers';
        $docTable = $receipt ? 'sales_invoices' : 'purchase_bills';

        $validated = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists($partyTable, 'id')->where('company_id', $tenant->id())],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                ->whereIn('control_type', ['cash', 'bank'])->where(fn ($q) => $q->where('is_group', false)->where('is_active', true))],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:500'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.id' => ['required', 'integer', Rule::exists($docTable, 'id')->where('company_id', $tenant->id())],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $partyClass = $receipt ? Customer::class : Supplier::class;
        $docClass = $receipt ? SalesInvoice::class : PurchaseBill::class;

        $base = $tenant->get()->base_currency;
        $currency = $validated['currency'] ?? $base;
        $fxRate = $currency === $base ? 1 : ($validated['fx_rate'] ?? 1);

        $payment = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $direction, $tenant, $partyClass, $docClass, $currency, $fxRate) {
            $party = $partyClass::findOrFail($validated['party_id']);
            $payment = Payment::create([
                'company_id' => $tenant->id(),
                'direction' => $direction,
                'party_type' => $party->getMorphClass(),
                'party_id' => $party->id,
                'payment_date' => $validated['payment_date'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount'],
                'currency' => $currency,
                'fx_rate' => $fxRate,
                'reference' => $validated['reference'] ?? null,
                'memo' => $validated['memo'] ?? null,
                'status' => 'draft',
                'created_by' => optional(auth()->user())->id,
            ]);

            foreach ($validated['allocations'] ?? [] as $alloc) {
                if (($alloc['amount'] ?? 0) <= 0) {
                    continue;
                }
                $payment->allocations()->create([
                    'company_id' => $tenant->id(),
                    'allocatable_type' => (new $docClass)->getMorphClass(),
                    'allocatable_id' => $alloc['id'],
                    'amount' => $alloc['amount'],
                ]);
            }

            return $payment;
        });

        try {
            $service->post($payment);
        } catch (PaymentException|PostingException $e) {
            $payment->allocations()->delete();
            $payment->delete();

            return back()->withErrors(['posting' => $e->getMessage()])->withInput();
        }

        return redirect()->route($receipt ? 'sales.receipts.show' : 'purchase.payments.show', $payment)->with('success', 'Payment posted.');
    }

    public function show(Request $request, Payment $payment): Response
    {
        $payment->load(['party:id,code,name', 'account:id,code,name', 'allocations.allocatable', 'reversalJournal:id,number']);
        $receipt = $payment->isReceipt();

        return Inertia::render('payments/show', [
            'direction' => $payment->direction,
            'payment' => [
                'id' => $payment->id,
                'number' => $payment->number,
                'date' => $payment->payment_date->toDateString(),
                'party' => $payment->party ? $payment->party->code.' — '.$payment->party->name : null,
                'account' => $payment->account->code.' — '.$payment->account->name,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'fx_rate' => (float) $payment->fx_rate,
                'unapplied' => $payment->unappliedAmount(),
                'reference' => $payment->reference,
                'memo' => $payment->memo,
                'status' => $payment->status,
                'journal_id' => $payment->journal_id,
                'reversal_journal_id' => $payment->reversal_journal_id,
                'reversal_journal' => $payment->reversalJournal?->number,
                'can_reverse' => $payment->status === 'posted',
                'allocations' => $payment->allocations->map(fn ($a) => [
                    'document' => $a->allocatable?->number,
                    'amount' => (float) $a->amount,
                ]),
            ],
        ]);
    }

    public function reverse(Payment $payment, PaymentService $service): RedirectResponse
    {
        try {
            $service->reverse($payment);
        } catch (PaymentException|PostingException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment reversed.');
    }
}
