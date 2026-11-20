<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Supplier;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Udhaar" — a plain, daily who-owes-whom screen:
 *   • Lena hai  → customers who still owe us (open receivables)
 *   • Dena hai  → suppliers we still owe (open payables)
 * Built straight from posted ledger lines, oldest activity first.
 */
class UdhaarController extends Controller
{
    public function index(TenantManager $tenant): Response
    {
        $companyId = $tenant->id();

        return Inertia::render('udhaar/index', [
            'receivables' => $this->balances($companyId, 'ar', Customer::class, 'customers', false),
            'payables' => $this->balances($companyId, 'ap', Supplier::class, 'suppliers', true),
        ]);
    }

    /**
     * Outstanding balance per party from posted journals.
     *
     * @return array<int, array{id:int,code:string,name:string,phone:?string,balance:float,last_date:?string}>
     */
    private function balances(?int $companyId, string $control, string $partyClass, string $table, bool $creditPositive): array
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
            ->groupBy('t.id', 't.code', 't.name', 't.phone')
            ->havingRaw("SUM({$sign}) > 0.005")
            ->select(
                't.id', 't.code', 't.name', 't.phone',
                DB::raw("SUM({$sign}) as bal"),
                DB::raw('MAX(j.entry_date) as last_date'),
            )
            ->orderByDesc('bal')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'phone' => $r->phone,
                'balance' => round((float) $r->bal, 2),
                'last_date' => $r->last_date ? Carbon::parse($r->last_date)->toDateString() : null,
            ])
            ->all();
    }
}
