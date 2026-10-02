<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\RecurringJournal;
use App\Recurring\RecurringJournalException;
use App\Recurring\RecurringJournalService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecurringJournalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Rec Co', 'code' => 'REC', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create(2026, $m, 1);
            AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => "M{$m}", 'starts_on' => $start, 'ends_on' => $start->copy()->endOfMonth(), 'status' => 'open']);
        }

        foreach ([['6200', 'Rent', 'expense'], ['1102', 'Bank', 'asset']] as [$code, $name, $type]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $type === 'asset' ? 'bank' : null]);
        }
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function lines(float $amount = 1000): array
    {
        return [
            ['account_id' => $this->acct('6200'), 'debit' => $amount, 'description' => 'Rent'],
            ['account_id' => $this->acct('1102'), 'credit' => $amount],
        ];
    }

    private function make(string $frequency, string $start, ?string $end = null, int $interval = 1): RecurringJournal
    {
        return app(RecurringJournalService::class)->create(
            ['name' => 'Rent', 'frequency' => $frequency, 'interval' => $interval, 'start_date' => $start, 'end_date' => $end],
            $this->lines(),
        );
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    public function test_an_unbalanced_template_is_rejected(): void
    {
        $this->expectException(RecurringJournalException::class);
        app(RecurringJournalService::class)->create(
            ['name' => 'Bad', 'frequency' => 'monthly', 'interval' => 1, 'start_date' => '2026-01-01'],
            [['account_id' => $this->acct('6200'), 'debit' => 1000], ['account_id' => $this->acct('1102'), 'credit' => 900]],
        );
    }

    public function test_run_due_catches_up_missed_periods_and_advances(): void
    {
        $t = $this->make('monthly', '2026-01-01');

        $result = app(RecurringJournalService::class)->runDue('2026-03-15');

        $this->assertSame(3, $result['generated']); // Jan, Feb, Mar
        $this->assertSame([], $result['errors']);
        $this->assertSame(3, Journal::where('type', 'recurring')->count());
        $this->assertEqualsWithDelta(3000.0, $this->gl('6200'), 0.001);
        $this->assertSame('2026-04-01', $t->refresh()->next_run_date->toDateString());
    }

    public function test_end_date_stops_generation_and_marks_ended(): void
    {
        $t = $this->make('monthly', '2026-01-01', '2026-02-28');

        $result = app(RecurringJournalService::class)->runDue('2026-06-01');

        $this->assertSame(2, $result['generated']); // Jan, Feb only
        $this->assertSame('ended', $t->refresh()->status);
    }

    public function test_a_locked_period_stops_catch_up_and_is_reported(): void
    {
        AccountingPeriod::where('name', 'M3')->update(['status' => 'locked']);
        $t = $this->make('monthly', '2026-01-01');

        $result = app(RecurringJournalService::class)->runDue('2026-05-01');

        $this->assertSame(2, $result['generated']); // Jan, Feb posted; Mar blocked
        $this->assertCount(1, $result['errors']);
        $this->assertSame('2026-03-01', $t->refresh()->next_run_date->toDateString()); // parked on the failing period
    }

    public function test_paused_templates_are_skipped(): void
    {
        $t = $this->make('monthly', '2026-01-01');
        app(RecurringJournalService::class)->pause($t);

        $result = app(RecurringJournalService::class)->runDue('2026-06-01');
        $this->assertSame(0, $result['generated']);

        app(RecurringJournalService::class)->resume($t->refresh());
        $result2 = app(RecurringJournalService::class)->runDue('2026-02-15');
        $this->assertSame(2, $result2['generated']);
    }

    public function test_quarterly_advances_three_months(): void
    {
        $t = $this->make('quarterly', '2026-01-01');

        $result = app(RecurringJournalService::class)->runDue('2026-05-01');
        $this->assertSame(2, $result['generated']); // Jan 1 and Apr 1
        $this->assertSame('2026-07-01', $t->refresh()->next_run_date->toDateString());
    }

    public function test_run_one_only_affects_its_own_template(): void
    {
        $a = $this->make('monthly', '2026-01-01');
        $b = $this->make('monthly', '2026-01-01');

        app(RecurringJournalService::class)->runOne($a, '2026-02-15');

        $this->assertSame('2026-03-01', $a->refresh()->next_run_date->toDateString());
        $this->assertSame('2026-01-01', $b->refresh()->next_run_date->toDateString()); // untouched
    }
}
