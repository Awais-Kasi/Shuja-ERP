<?php

namespace Tests\Feature;

use App\Inventory\InventoryService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Purchasing\PurchasingException;
use App\Purchasing\PurchaseReturnService;
use App\Sales\SalesException;
use App\Sales\SalesInvoiceService;
use App\Sales\SalesReturnService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReturnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $wh;

    private Item $item;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Ret Co', 'code' => 'RET', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);

        foreach ([
            ['1123', 'Finished Goods', 'asset', 'inventory'], ['1110', 'AR', 'asset', 'ar'], ['2110', 'AP', 'liability', 'ap'],
            ['2120', 'Output Tax', 'liability', 'tax'], ['1130', 'Input Tax', 'asset', 'tax'],
            ['4100', 'Sales', 'income', null], ['5100', 'COGS', 'expense', null],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }

        $uom = Uom::create(['company_id' => $this->company->id, 'code' => 'U', 'name' => 'Unit']);
        $this->wh = Warehouse::create(['company_id' => $this->company->id, 'code' => 'FG', 'name' => 'FG', 'type' => 'warehouse']);
        $this->item = Item::create(['company_id' => $this->company->id, 'code' => 'W', 'name' => 'Widget', 'uom_id' => $uom->id, 'inventory_account_id' => $this->acct('1123'), 'income_account_id' => $this->acct('4100'), 'cogs_account_id' => $this->acct('5100'), 'valuation_method' => 'weighted_average']);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C', 'name' => 'Cust', 'receivable_account_id' => $this->acct('1110')]);
        $this->supplier = Supplier::create(['company_id' => $this->company->id, 'code' => 'S', 'name' => 'Supp', 'payable_account_id' => $this->acct('2110')]);

        app(InventoryService::class)->receive($this->item, $this->wh, 50, 100); // 50 @ 100
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    private function stockQty(): float
    {
        return (float) (StockBalance::where('item_id', $this->item->id)->where('warehouse_id', $this->wh->id)->value('quantity') ?? 0);
    }

    private function postInvoice(float $qty, float $rate): SalesInvoice
    {
        $inv = SalesInvoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'invoice_date' => now()->toDateString(), 'status' => 'draft', 'subtotal' => 0, 'tax_amount' => 0, 'total' => 0, 'cogs_total' => 0]);
        $inv->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => $qty, 'rate' => $rate, 'amount' => round($qty * $rate, 4)]);

        return app(SalesInvoiceService::class)->post($inv)->load('lines');
    }

    public function test_sales_return_restocks_and_reverses_revenue_and_cogs(): void
    {
        $inv = $this->postInvoice(20, 150); // sells 20 @ cost 100, price 150 → stock 30
        $line = $inv->lines->first();
        $this->assertEqualsWithDelta(2000, (float) $line->cost, 0.01); // cost snapshot

        $return = SalesReturn::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'sales_invoice_id' => $inv->id, 'warehouse_id' => $this->wh->id, 'return_date' => now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $return->lines()->create(['company_id' => $this->company->id, 'sales_invoice_line_id' => $line->id, 'item_id' => $this->item->id, 'quantity' => 5, 'rate' => 150, 'amount' => 750]);
        app(SalesReturnService::class)->post($return);

        $this->assertEqualsWithDelta(35, $this->stockQty(), 0.001);              // 30 + 5 back
        $this->assertEqualsWithDelta(5, (float) $line->refresh()->returned_qty, 0.001);
        $this->assertEqualsWithDelta(-2250, $this->gl('4100'), 0.01);           // 3000 sold - 750 returned
        $this->assertEqualsWithDelta(2250, $this->gl('1110'), 0.01);            // AR net
        $this->assertEqualsWithDelta(1500, $this->gl('5100'), 0.01);            // COGS 2000 - 500
    }

    public function test_sales_return_cannot_exceed_invoiced_quantity(): void
    {
        $inv = $this->postInvoice(10, 150);
        $line = $inv->lines->first();

        $return = SalesReturn::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'sales_invoice_id' => $inv->id, 'warehouse_id' => $this->wh->id, 'return_date' => now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $return->lines()->create(['company_id' => $this->company->id, 'sales_invoice_line_id' => $line->id, 'item_id' => $this->item->id, 'quantity' => 15, 'rate' => 150, 'amount' => 2250]);

        $this->expectException(SalesException::class);
        app(SalesReturnService::class)->post($return);
    }

    public function test_reversing_a_sales_return_removes_the_restocked_goods(): void
    {
        $inv = $this->postInvoice(20, 150);
        $line = $inv->lines->first();
        $return = SalesReturn::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'sales_invoice_id' => $inv->id, 'warehouse_id' => $this->wh->id, 'return_date' => now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $return->lines()->create(['company_id' => $this->company->id, 'sales_invoice_line_id' => $line->id, 'item_id' => $this->item->id, 'quantity' => 5, 'rate' => 150, 'amount' => 750]);
        app(SalesReturnService::class)->post($return);

        app(SalesReturnService::class)->reverse($return->refresh());

        $this->assertSame('reversed', $return->refresh()->status);
        $this->assertEqualsWithDelta(30, $this->stockQty(), 0.001);            // back to post-invoice level
        $this->assertEqualsWithDelta(0, (float) $line->refresh()->returned_qty, 0.001);
        $this->assertEqualsWithDelta(-3000, $this->gl('4100'), 0.01);          // only the original sale remains
    }

    public function test_purchase_return_ships_goods_and_reduces_payable(): void
    {
        $return = PurchaseReturn::create(['company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'return_date' => now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $return->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => 10, 'rate' => 0, 'amount' => 0]);
        app(PurchaseReturnService::class)->post($return);

        $this->assertEqualsWithDelta(40, $this->stockQty(), 0.001);            // 50 - 10
        $this->assertEqualsWithDelta(1000, $this->gl('2110'), 0.01);           // AP debited (payable reduced)
        $this->assertEqualsWithDelta(-1000, $this->gl('1123'), 0.01);          // inventory credited
    }

    public function test_purchase_return_with_tax_reverses_input_tax(): void
    {
        $return = PurchaseReturn::create(['company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'return_date' => now()->toDateString(), 'tax_amount' => 150, 'status' => 'draft']);
        $return->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => 10, 'rate' => 0, 'amount' => 0]);
        app(PurchaseReturnService::class)->post($return);

        $this->assertEqualsWithDelta(1150, $this->gl('2110'), 0.01);           // AP debited cost + tax
        $this->assertEqualsWithDelta(-150, $this->gl('1130'), 0.01);           // input tax credited
    }

    public function test_reversing_a_purchase_return_restores_stock_and_payable(): void
    {
        $return = PurchaseReturn::create(['company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'return_date' => now()->toDateString(), 'tax_amount' => 0, 'status' => 'draft']);
        $return->lines()->create(['company_id' => $this->company->id, 'item_id' => $this->item->id, 'quantity' => 10, 'rate' => 0, 'amount' => 0]);
        app(PurchaseReturnService::class)->post($return);

        app(PurchaseReturnService::class)->reverse($return->refresh());

        $this->assertSame('reversed', $return->refresh()->status);
        $this->assertEqualsWithDelta(50, $this->stockQty(), 0.001);
        $this->assertEqualsWithDelta(0, $this->gl('2110'), 0.01);              // AP net zero
        $this->assertEqualsWithDelta(0, $this->gl('1123'), 0.01);              // inventory net zero
    }
}
