<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\WageWorker;
use App\Payroll\WageException;
use App\Payroll\WageService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private WageWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Wage Co', 'code' => 'W', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        Account::create(['company_id' => $this->company->id, 'code' => '2140', 'name' => 'Wages Payable', 'type' => 'liability', 'control_type' => 'wages_payable']);
        Account::create(['company_id' => $this->company->id, 'code' => '6100', 'name' => 'Salaries & Wages', 'type' => 'expense']);
        Account::create(['company_id' => $this->company->id, 'code' => '1101', 'name' => 'Cash', 'type' => 'asset', 'control_type' => 'cash']);

        $this->worker = WageWorker::create(['company_id' => $this->company->id, 'code' => 'W-01', 'name' => 'Daily Worker', 'rate_per_kg' => 5]);
    }

    public function test_recording_per_kg_work_raises_the_wallet_and_books_wages(): void
    {
        $service = app(WageService::class);

        $service->recordWork($this->worker, ['entry_date' => Carbon::now()->toDateString(), 'quantity' => 100, 'rate' => 5]);
        $service->recordWork($this->worker, ['entry_date' => Carbon::now()->toDateString(), 'quantity' => 200, 'rate' => 5]);

        // 100*5 + 200*5 = 1500 earned, none paid yet.
        $this->assertEqualsWithDelta(1500, $this->worker->fresh()->walletBalance(), 0.01);

        // Wages expense debited, worker's payable credited.
        $this->assertEqualsWithDelta(1500, $this->accountMovement('6100'), 0.01);
        $this->assertEqualsWithDelta(1500, $this->workerPayable(), 0.01);
    }

    public function test_paying_a_worker_lowers_the_wallet(): void
    {
        $service = app(WageService::class);
        $service->recordWork($this->worker, ['entry_date' => Carbon::now()->toDateString(), 'quantity' => 300, 'rate' => 5]); // 1500
        $cash = Account::where('code', '1101')->first();

        $service->pay($this->worker, ['payment_date' => Carbon::now()->toDateString(), 'amount' => 1000, 'account_id' => $cash->id]);

        // 1500 earned - 1000 paid = 500 still owed.
        $this->assertEqualsWithDelta(500, $this->worker->fresh()->walletBalance(), 0.01);
        $this->assertEqualsWithDelta(500, $this->workerPayable(), 0.01);
        // Cash credited 1000.
        $this->assertEqualsWithDelta(-1000, $this->accountMovement('1101'), 0.01);
    }

    public function test_pay_must_use_cash_or_bank(): void
    {
        $service = app(WageService::class);
        $expense = Account::where('code', '6100')->first();
        $this->expectException(WageException::class);
        $service->pay($this->worker, ['payment_date' => Carbon::now()->toDateString(), 'amount' => 100, 'account_id' => $expense->id]);
    }

    /** Net debit on an account from posted journals. */
    private function accountMovement(string $code): float
    {
        return (float) DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->where('j.status', 'posted')
            ->sum(DB::raw('l.debit - l.credit'));
    }

    /** What is owed to the worker on the wages-payable control account (credit balance). */
    private function workerPayable(): float
    {
        return (float) DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.control_type', 'wages_payable')
            ->where('l.party_type', (new WageWorker)->getMorphClass())
            ->where('l.party_id', $this->worker->id)
            ->where('j.status', 'posted')
            ->sum(DB::raw('l.credit - l.debit'));
    }
}
