<?php

namespace App\Reports;

use App\Models\Company;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;

/**
 * Aged receivables / payables — open invoices and bills bucketed by how far past due
 * they are as of a date. Foreign-currency documents are converted to the base ledger
 * currency at their booking rate, so the report ties to the AR/AP control balance.
 */
class AgingReports
{
    private const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct(private readonly TenantManager $tenant) {}

    private function company(): ?Company
    {
        return $this->tenant->get();
    }

    public function receivables(string $asOf): array
    {
        $invoices = SalesInvoice::query()
            ->where('status', 'posted')
            ->whereColumn('amount_paid', '<', 'total')
            ->whereDate('invoice_date', '<=', $asOf)
            ->with('customer:id,code,name')
            ->get();

        return $this->build($asOf, $invoices, fn ($inv) => [
            'party' => $inv->customer,
            'due' => $inv->due_date ?: $inv->invoice_date,
            'outstanding' => round((float) $inv->total - (float) $inv->amount_paid, 4),
            'currency' => $inv->currency,
            'rate' => (float) $inv->fx_rate ?: 1.0,
        ]);
    }

    public function payables(string $asOf): array
    {
        $bills = PurchaseBill::query()
            ->where('status', 'posted')
            ->whereColumn('amount_paid', '<', 'total')
            ->whereDate('bill_date', '<=', $asOf)
            ->with('supplier:id,code,name')
            ->get();

        return $this->build($asOf, $bills, fn ($bill) => [
            'party' => $bill->supplier,
            'due' => $bill->due_date ?: $bill->bill_date,
            'outstanding' => round((float) $bill->total - (float) $bill->amount_paid, 4),
            'currency' => $bill->currency,
            'rate' => (float) $bill->fx_rate ?: 1.0,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $documents
     */
    private function build(string $asOf, $documents, callable $extract): array
    {
        $asOfC = Carbon::parse($asOf);
        $base = optional($this->company())->base_currency ?: 'PKR';
        $byParty = [];
        $hasForeign = false;

        foreach ($documents as $doc) {
            $x = $extract($doc);
            $party = $x['party'];
            if (! $party || $x['outstanding'] <= 0.0001) {
                continue;
            }
            // Age and total in the base ledger currency so the report ties to the AR/AP control.
            $currency = $x['currency'] ?: $base;
            $rate = $x['currency'] && $x['currency'] !== $base ? ($x['rate'] ?: 1.0) : 1.0;
            $baseOutstanding = round($x['outstanding'] * $rate, 4);

            $due = Carbon::parse($x['due']);
            $daysPast = $due->lt($asOfC) ? $due->diffInDays($asOfC) : 0;
            $bucket = $this->bucketFor($daysPast);

            $key = $party->id;
            if (! isset($byParty[$key])) {
                $byParty[$key] = ['party' => $party->code.' — '.$party->name, 'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0, 'foreign' => []];
            }
            $byParty[$key][$bucket] = round($byParty[$key][$bucket] + $baseOutstanding, 2);
            $byParty[$key]['total'] = round($byParty[$key]['total'] + $baseOutstanding, 2);
            if ($currency !== $base) {
                $hasForeign = true;
                $byParty[$key]['foreign'][$currency] = round(($byParty[$key]['foreign'][$currency] ?? 0) + $x['outstanding'], 2);
            }
        }

        // Render the per-currency exposure as a compact label, e.g. "USD 3,000".
        foreach ($byParty as &$row) {
            $row['currencies'] = $row['foreign']
                ? implode(', ', array_map(fn ($cur, $amt) => $cur.' '.number_format($amt, 2), array_keys($row['foreign']), $row['foreign']))
                : '';
            unset($row['foreign']);
        }
        unset($row);

        $rows = array_values($byParty);
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        $totals = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
        foreach ($rows as $r) {
            foreach (array_merge(self::BUCKETS, ['total']) as $k) {
                $totals[$k] = round($totals[$k] + $r[$k], 2);
            }
        }

        return ['as_of' => $asOf, 'base_currency' => $base, 'has_foreign' => $hasForeign, 'rows' => $rows, 'totals' => $totals];
    }

    private function bucketFor(int $daysPast): string
    {
        return match (true) {
            $daysPast <= 0 => 'current',
            $daysPast <= 30 => 'd1_30',
            $daysPast <= 60 => 'd31_60',
            $daysPast <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }
}
