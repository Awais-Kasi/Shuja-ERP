<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Journal;
use App\Models\PayrollRun;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, TenantManager $tenant): Response
    {
        $company = $tenant->get();
        $companyId = $company?->id;

        $fiscalYear = $company
            ? FiscalYear::query()->orderByDesc('starts_on')->first()
            : null;

        // Net movement per control account across posted journals (debit positive).
        $byControl = $companyId
            ? DB::table('journal_lines as l')
                ->join('journals as j', 'j.id', '=', 'l.journal_id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.company_id', $companyId)
                ->where('j.status', 'posted')
                ->whereNotNull('a.control_type')
                ->groupBy('a.control_type')
                ->select('a.control_type', DB::raw('SUM(l.base_debit - l.base_credit) as net'))
                ->pluck('net', 'a.control_type')
            : collect();

        $net = fn (string $key) => round((float) ($byControl[$key] ?? 0), 2);

        $inventoryValue = $companyId ? round((float) StockBalance::sum('value'), 2) : 0.0;

        return Inertia::render('dashboard', [
            'kpis' => [
                'cash' => $net('cash'),
                'bank' => $net('bank'),
                'receivables' => $net('ar'),
                'payables' => -$net('ap'),           // payables carry a credit balance
                'inventory' => $inventoryValue,
                'input_tax' => $net('tax'),
            ],
            'stats' => [
                'members' => $company ? $company->users()->count() : 0,
                'currencies' => Currency::where('is_active', true)->count(),
                'periods' => $fiscalYear ? $fiscalYear->periods()->count() : 0,
                'openPeriods' => $fiscalYear ? $fiscalYear->periods()->where('status', 'open')->count() : 0,
            ],
            'counts' => [
                'customers' => $companyId ? Customer::count() : 0,
                'suppliers' => $companyId ? Supplier::count() : 0,
                'items' => $companyId ? Item::count() : 0,
                'employees' => $companyId ? Employee::where('is_active', true)->count() : 0,
            ],
            'trend' => $this->revenueExpenseTrend($companyId),
            'recent' => $this->recentJournals($companyId),
            'topItems' => $this->topItems($companyId),
            'receivables' => $this->partyBalances($companyId, 'ar', Customer::class, 'customers', false),
            'payables' => $this->partyBalances($companyId, 'ap', Supplier::class, 'suppliers', true),
            'payroll' => $this->payrollSummary($companyId),
            'fiscalYear' => $fiscalYear ? [
                'name' => $fiscalYear->name,
                'starts_on' => $fiscalYear->starts_on->toDateString(),
                'ends_on' => $fiscalYear->ends_on->toDateString(),
                'status' => $fiscalYear->status,
            ] : null,
            'role' => $request->user()->currentRole()?->name,
        ]);
    }

    /**
     * Revenue vs expense for the last six months, from posted journals.
     *
     * @return array<int, array{month:string,revenue:float,expense:float}>
     */
    private function revenueExpenseTrend(?int $companyId): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(5);

        $rows = $companyId
            ? DB::table('journal_lines as l')
                ->join('journals as j', 'j.id', '=', 'l.journal_id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.company_id', $companyId)
                ->where('j.status', 'posted')
                ->whereIn('a.type', ['income', 'expense'])
                ->whereDate('j.entry_date', '>=', $start->toDateString())
                ->groupBy(DB::raw("to_char(j.entry_date, 'YYYY-MM')"), 'a.type')
                ->select(DB::raw("to_char(j.entry_date, 'YYYY-MM') as ym"), 'a.type', DB::raw('SUM(l.base_credit - l.base_debit) as amt'))
                ->get()
            : collect();

        $lookup = [];
        foreach ($rows as $r) {
            $lookup[$r->ym][$r->type] = (float) $r->amt;
        }

        $trend = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $start->copy()->addMonths($i);
            $ym = $m->format('Y-m');
            $trend[] = [
                'month' => $m->format('M'),
                'revenue' => round((float) ($lookup[$ym]['income'] ?? 0), 2),      // income credit-positive
                'expense' => round(-(float) ($lookup[$ym]['expense'] ?? 0), 2),     // expense debit-positive → flip sign
            ];
        }

        return $trend;
    }

    /**
     * @return array<int, array{number:?string,date:string,type:string,memo:?string,amount:float}>
     */
    private function recentJournals(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return Journal::where('status', 'posted')
            ->withSum('lines as total_debit', 'base_debit')
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (Journal $j) => [
                'number' => $j->number,
                'date' => $j->entry_date->toDateString(),
                'type' => str_replace('_', ' ', (string) $j->type),
                'memo' => $j->memo,
                'amount' => round((float) $j->total_debit, 2),
            ])->all();
    }

    /**
     * Outstanding balance per party (customer receivables / supplier payables).
     *
     * @return array<int, array{name:string,balance:float}>
     */
    private function partyBalances(?int $companyId, string $control, string $partyClass, string $table, bool $creditPositive): array
    {
        if (! $companyId) {
            return [];
        }

        $morph = (new $partyClass)->getMorphClass();
        $sign = $creditPositive ? 'l.base_credit - l.base_debit' : 'l.base_debit - l.base_credit';

        return DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join("{$table} as t", 't.id', '=', 'l.party_id')
            ->where('j.company_id', $companyId)->where('j.status', 'posted')
            ->where('a.control_type', $control)
            ->where('l.party_type', $morph)
            ->groupBy('t.id', 't.name')
            ->havingRaw("SUM({$sign}) > 0.005")
            ->select('t.name', DB::raw("SUM({$sign}) as bal"))
            ->orderByDesc('bal')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'balance' => round((float) $r->bal, 2)])
            ->all();
    }

    /**
     * @return array{number:?string,period:?string,status:?string,employees:int,gross:float,net:float}|null
     */
    private function payrollSummary(?int $companyId): ?array
    {
        if (! $companyId) {
            return null;
        }

        $run = PayrollRun::whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->withCount('payslips')
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->first();

        if (! $run) {
            return null;
        }

        $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

        return [
            'number' => $run->number,
            'period' => $months[$run->period_month].' '.$run->period_year,
            'status' => $run->status,
            'employees' => $run->payslips_count,
            'gross' => round((float) $run->gross_total, 2),
            'net' => round((float) $run->net_total, 2),
        ];
    }

    /**
     * @return array<int, array{code:string,name:string,value:float}>
     */
    private function topItems(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return DB::table('stock_balances as s')
            ->join('items as i', 'i.id', '=', 's.item_id')
            ->where('s.company_id', $companyId)
            ->groupBy('i.id', 'i.code', 'i.name')
            ->havingRaw('SUM(s.value) > 0')
            ->select('i.code', 'i.name', DB::raw('SUM(s.value) as val'))
            ->orderByDesc('val')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'value' => round((float) $r->val, 2)])
            ->all();
    }
}
