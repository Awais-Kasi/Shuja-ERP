<?php

namespace App\Reports;

use App\Models\Account;
use App\Models\ConsignmentSettlement;
use App\Models\PurchaseBill;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Sales-tax (GST/VAT) return for a period: output tax on sales less input tax on
 * purchases, with supporting output and input registers. Credit notes reduce output
 * tax and debit notes reduce input tax. A ledger cross-check ties the register totals
 * to the tax control accounts.
 */
class TaxReports
{
    public function __construct(private readonly TenantManager $tenant) {}

    public function salesTax(string $from, string $to): array
    {
        // Output register — sales invoices (positive) and credit notes (negative).
        $output = [];
        foreach (SalesInvoice::where('status', 'posted')->where('tax_amount', '>', 0)
            ->whereDate('invoice_date', '>=', $from)->whereDate('invoice_date', '<=', $to)
            ->with('customer:id,code,name')->orderBy('invoice_date')->get() as $i) {
            $output[] = ['type' => 'Invoice', 'number' => $i->number, 'date' => $i->invoice_date->toDateString(), 'party' => $i->customer?->name, 'taxable' => (float) $i->subtotal, 'tax' => (float) $i->tax_amount];
        }
        // Consignment settlements recognise sales (and output tax) outside the invoice flow.
        foreach (ConsignmentSettlement::where('status', 'posted')->where('tax_amount', '>', 0)
            ->whereDate('settlement_date', '>=', $from)->whereDate('settlement_date', '<=', $to)
            ->with('agentCustomer:id,code,name')->orderBy('settlement_date')->get() as $s) {
            $output[] = ['type' => 'Consignment', 'number' => $s->number, 'date' => $s->settlement_date->toDateString(), 'party' => $s->agentCustomer?->name, 'taxable' => (float) $s->subtotal, 'tax' => (float) $s->tax_amount];
        }
        foreach (SalesReturn::where('status', 'posted')->where('tax_amount', '>', 0)
            ->whereDate('return_date', '>=', $from)->whereDate('return_date', '<=', $to)
            ->with('customer:id,code,name')->orderBy('return_date')->get() as $r) {
            $output[] = ['type' => 'Credit note', 'number' => $r->number, 'date' => $r->return_date->toDateString(), 'party' => $r->customer?->name, 'taxable' => -(float) $r->subtotal, 'tax' => -(float) $r->tax_amount];
        }

        // Input register — purchase bills (positive) and debit notes (negative).
        $input = [];
        foreach (PurchaseBill::where('status', 'posted')->where('tax_amount', '>', 0)
            ->whereDate('bill_date', '>=', $from)->whereDate('bill_date', '<=', $to)
            ->with('supplier:id,code,name')->orderBy('bill_date')->get() as $b) {
            $input[] = ['type' => 'Bill', 'number' => $b->number, 'date' => $b->bill_date->toDateString(), 'party' => $b->supplier?->name, 'taxable' => (float) $b->subtotal, 'tax' => (float) $b->tax_amount];
        }
        foreach (PurchaseReturn::where('status', 'posted')->where('tax_amount', '>', 0)
            ->whereDate('return_date', '>=', $from)->whereDate('return_date', '<=', $to)
            ->with('supplier:id,code,name')->orderBy('return_date')->get() as $r) {
            $input[] = ['type' => 'Debit note', 'number' => $r->number, 'date' => $r->return_date->toDateString(), 'party' => $r->supplier?->name, 'taxable' => -(float) $r->subtotal, 'tax' => -(float) $r->tax_amount];
        }

        $outputTax = round(array_sum(array_column($output, 'tax')), 2);
        $inputTax = round(array_sum(array_column($input, 'tax')), 2);
        $taxableSales = round(array_sum(array_column($output, 'taxable')), 2);
        $taxablePurchases = round(array_sum(array_column($input, 'taxable')), 2);

        return [
            'from' => $from, 'to' => $to,
            'summary' => [
                'taxable_sales' => $taxableSales, 'output_tax' => $outputTax,
                'taxable_purchases' => $taxablePurchases, 'input_tax' => $inputTax,
                'net_payable' => round($outputTax - $inputTax, 2),
                'ledger_output_tax' => $this->ledgerMovement('liability', $from, $to, true),
                'ledger_input_tax' => $this->ledgerMovement('asset', $from, $to, false),
            ],
            'output' => $output,
            'input' => $input,
        ];
    }

    /**
     * Net movement on a tax control account over the period: credit-positive for the
     * output (liability) account, debit-positive for the input (asset) account.
     */
    private function ledgerMovement(string $accountType, string $from, string $to, bool $creditPositive): float
    {
        $accountIds = Account::where('control_type', 'tax')->where('type', $accountType)->pluck('id')->all();
        if (! $accountIds) {
            return 0.0;
        }

        $sum = (float) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.company_id', $this->tenant->id())->where('j.status', 'posted')
            ->whereIn('l.account_id', $accountIds)
            ->whereDate('j.entry_date', '>=', $from)->whereDate('j.entry_date', '<=', $to)
            ->sum(DB::raw($creditPositive ? 'l.base_credit - l.base_debit' : 'l.base_debit - l.base_credit'));

        return round($sum, 2);
    }
}
