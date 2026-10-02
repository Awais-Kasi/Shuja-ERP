<?php

namespace Tests\Feature;

use App\Fx\FxRevaluationException;
use App\Fx\FxRevaluationService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FiscalYear;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FxRevaluationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'FX Co', 'code' => 'FX', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'Y', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        foreach ([
            ['1102', 'Bank', 'asset', 'bank'], ['2110', 'AP', 'liability', 'ap'], ['3100', 'Capital', 'equity', null],
            ['6100', 'Purchases', 'expense', null], ['4400', 'FX Gain/(Loss)', 'income', null],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function rate(string $ccy, float $rate, string $date): void
    {
        ExchangeRate::create(['company_id' => $this->company->id, 'base_code' => 'PKR', 'quote_code' => $ccy, 'rate' => $rate, 'rate_date' => $date, 'source' => 'test']);
    }

    /** Post a two-sided entry with both legs in a foreign currency at the given rate. */
    private function postFx(int $debit, int $credit, float $amount, string $ccy, float $rate, string $date): void
    {
        app(PostingEngine::class)->post(new LedgerEntry(
            entryDate: $date,
            lines: [
                new LedgerLine(accountId: $debit, debit: $amount, currency: $ccy, fxRate: $rate),
                new LedgerLine(accountId: $credit, credit: $amount, currency: $ccy, fxRate: $rate),
            ],
            type: 'manual',
        ));
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    public function test_revaluing_a_foreign_payable_books_a_loss_when_the_rate_rises(): void
    {
        // Buy on credit in USD at 280 → AP 1,000 USD, carrying 280,000 PKR.
        $this->postFx($this->acct('6100'), $this->acct('2110'), 1000, 'USD', 280, '2026-01-15');
        $this->rate('USD', 290, '2026-03-31');

        $reval = app(FxRevaluationService::class)->run('2026-03-31');

        $this->assertEqualsWithDelta(-290000.0, $this->gl('2110'), 0.01);  // liability now 290k
        $this->assertEqualsWithDelta(10000.0, $this->gl('4400'), 0.01);    // FX loss (debit)
        $this->assertEqualsWithDelta(10000.0, (float) $reval->total_loss, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $reval->total_gain, 0.01);
    }

    public function test_repeated_revaluation_only_posts_the_increment(): void
    {
        $this->postFx($this->acct('6100'), $this->acct('2110'), 1000, 'USD', 280, '2026-01-15');
        $service = app(FxRevaluationService::class);

        $this->rate('USD', 290, '2026-03-31');
        $service->run('2026-03-31'); // -10,000

        $this->rate('USD', 300, '2026-06-30');
        $service->run('2026-06-30'); // increment only another -10,000

        $this->assertEqualsWithDelta(-300000.0, $this->gl('2110'), 0.01);
        $this->assertEqualsWithDelta(20000.0, $this->gl('4400'), 0.01); // 10k + 10k, not 30k
    }

    public function test_a_foreign_bank_balance_books_a_gain_when_the_rate_rises(): void
    {
        // Hold 500 USD in the bank at 280 → 140,000 PKR carrying.
        $this->postFx($this->acct('1102'), $this->acct('3100'), 500, 'USD', 280, '2026-01-10');
        $this->rate('USD', 290, '2026-03-31');

        $reval = app(FxRevaluationService::class)->run('2026-03-31');

        $this->assertEqualsWithDelta(145000.0, $this->gl('1102'), 0.01);   // bank base raised
        $this->assertEqualsWithDelta(-5000.0, $this->gl('4400'), 0.01);    // FX gain (credit)
        $this->assertEqualsWithDelta(5000.0, (float) $reval->total_gain, 0.01);
    }

    public function test_mixed_gain_and_loss_post_both_fx_legs_and_balance(): void
    {
        $this->postFx($this->acct('6100'), $this->acct('2110'), 1000, 'USD', 280, '2026-01-15'); // AP → loss on rise
        $this->postFx($this->acct('1102'), $this->acct('3100'), 500, 'USD', 280, '2026-01-10');  // bank → gain on rise
        $this->rate('USD', 290, '2026-03-31');

        $reval = app(FxRevaluationService::class)->run('2026-03-31');

        $this->assertEqualsWithDelta(5000.0, (float) $reval->total_gain, 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $reval->total_loss, 0.01);
        // Journal balances.
        $j = $reval->journal;
        $this->assertEqualsWithDelta((float) $j->lines->sum('base_debit'), (float) $j->lines->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(5000.0, $this->gl('4400'), 0.01); // net: 10k loss (Dr) - 5k gain (Cr)
    }

    public function test_missing_rate_is_rejected(): void
    {
        $this->postFx($this->acct('6100'), $this->acct('2110'), 1000, 'USD', 280, '2026-01-15');

        $this->expectException(FxRevaluationException::class);
        app(FxRevaluationService::class)->run('2026-03-31'); // no rate seeded
    }

    public function test_nothing_to_revalue_is_rejected(): void
    {
        $this->postFx($this->acct('6100'), $this->acct('2110'), 1000, 'USD', 280, '2026-01-15');
        $this->rate('USD', 280, '2026-03-31'); // same rate → zero adjustment

        $this->expectException(FxRevaluationException::class);
        app(FxRevaluationService::class)->run('2026-03-31');
    }

    public function test_revaluation_date_must_advance(): void
    {
        $this->postFx($this->acct('6100'), $this->acct('2110'), 1000, 'USD', 280, '2026-01-15');
        $service = app(FxRevaluationService::class);
        $this->rate('USD', 290, '2026-03-31');
        $service->run('2026-03-31');

        $this->rate('USD', 300, '2026-02-28');
        $this->expectException(FxRevaluationException::class);
        $service->run('2026-02-28'); // earlier than the last revaluation
    }

    public function test_non_monetary_foreign_balances_are_not_revalued(): void
    {
        // The expense leg (6100) is also in USD but is not a monetary account.
        $this->postFx($this->acct('6100'), $this->acct('2110'), 1000, 'USD', 280, '2026-01-15');
        $this->rate('USD', 290, '2026-03-31');

        $rows = app(FxRevaluationService::class)->computeRows('2026-03-31');
        $codes = array_column($rows, 'code');
        $this->assertContains('2110', $codes);
        $this->assertNotContains('6100', $codes);
    }
}
