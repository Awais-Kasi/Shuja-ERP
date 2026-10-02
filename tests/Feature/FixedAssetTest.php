<?php

namespace Tests\Feature;

use App\FixedAssets\FixedAssetException;
use App\FixedAssets\FixedAssetService;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FiscalYear;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private int $year;

    private int $month;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = (int) Carbon::now()->year;
        $this->month = (int) Carbon::now()->month;
        $this->date = Carbon::now()->toDateString();

        $this->company = Company::create(['name' => 'FA Co', 'code' => 'FA', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        foreach ([
            ['1510', 'PPE', 'asset', null], ['1520', 'Accum Dep', 'asset', null], ['6600', 'Depreciation', 'expense', null],
            ['1102', 'Bank', 'asset', 'bank'], ['4200', 'Other Income', 'income', null], ['6900', 'Misc Expense', 'expense', null],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }
    }

    private function asset(float $cost, float $salvage, int $life, ?string $date = null): FixedAsset
    {
        static $n = 0;
        $n++;

        return FixedAsset::create([
            'company_id' => $this->company->id,
            'code' => 'FA-'.$n,
            'name' => "Asset {$n}",
            'asset_account_id' => Account::where('code', '1510')->value('id'),
            'accum_account_id' => Account::where('code', '1520')->value('id'),
            'depreciation_account_id' => Account::where('code', '6600')->value('id'),
            'cost' => $cost,
            'salvage_value' => $salvage,
            'useful_life_months' => $life,
            'acquisition_date' => $date ?? $this->date,
            'status' => 'active',
        ]);
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)
            ->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    public function test_acquisition_posts_ppe_debit_and_funding_credit(): void
    {
        $asset = $this->asset(500000, 0, 60);
        app(FixedAssetService::class)->acquire($asset, (int) Account::where('code', '1102')->value('id'));

        $this->assertEqualsWithDelta(500000.0, $this->gl('1510'), 0.001);
        $this->assertEqualsWithDelta(-500000.0, $this->gl('1102'), 0.001);
        $this->assertNotNull($asset->refresh()->journal_id);
    }

    public function test_depreciation_posts_expense_and_accumulates(): void
    {
        $asset = $this->asset(1200, 200, 10); // depreciable 1000, monthly 100
        $service = app(FixedAssetService::class);
        $service->acquire($asset, (int) Account::where('code', '1102')->value('id'));

        $run = $service->runDepreciation($this->year, $this->month);

        $this->assertEqualsWithDelta(100.0, (float) $run->total_amount, 0.001);
        $this->assertEqualsWithDelta(100.0, $this->gl('6600'), 0.001);   // Dr depreciation
        $this->assertEqualsWithDelta(-100.0, $this->gl('1520'), 0.001);  // Cr accumulated
        $this->assertEqualsWithDelta(100.0, (float) $asset->refresh()->accumulated_depreciation, 0.001);
        // journal balances
        $j = $run->journal;
        $this->assertEqualsWithDelta((float) $j->lines->sum('base_debit'), (float) $j->lines->sum('base_credit'), 0.001);
    }

    public function test_depreciation_caps_and_marks_fully_depreciated(): void
    {
        $asset = $this->asset(1000, 0, 1); // one month life → charge whole depreciable base
        $service = app(FixedAssetService::class);
        $service->acquire($asset, (int) Account::where('code', '1102')->value('id'));

        $service->runDepreciation($this->year, $this->month);

        $asset->refresh();
        $this->assertEqualsWithDelta(1000.0, (float) $asset->accumulated_depreciation, 0.001);
        $this->assertSame('fully_depreciated', $asset->status);
    }

    public function test_disposal_recognises_gain(): void
    {
        $asset = $this->asset(1000, 0, 10);
        $service = app(FixedAssetService::class);
        $service->acquire($asset, (int) Account::where('code', '1102')->value('id'));

        $service->dispose($asset, 1200, $this->date, (int) Account::where('code', '1102')->value('id'));

        // Book value 1000, proceeds 1200 → gain 200 credited to 4200; PPE removed.
        $this->assertEqualsWithDelta(0.0, $this->gl('1510'), 0.001);      // 1000 in − 1000 out
        $this->assertEqualsWithDelta(-200.0, $this->gl('4200'), 0.001);   // gain (credit)
        $this->assertSame('disposed', $asset->refresh()->status);
    }

    public function test_disposal_recognises_loss(): void
    {
        $asset = $this->asset(1000, 0, 10);
        $service = app(FixedAssetService::class);
        $service->acquire($asset, (int) Account::where('code', '1102')->value('id'));

        $service->dispose($asset, 700, $this->date, (int) Account::where('code', '1102')->value('id'));

        $this->assertEqualsWithDelta(300.0, $this->gl('6900'), 0.001);    // loss (debit)
        $this->assertEqualsWithDelta(0.0, $this->gl('1510'), 0.001);
    }

    public function test_depreciation_clears_the_exact_remainder_in_the_final_month(): void
    {
        // cost 1000, salvage 100 → base 900, life 7 → straight round(900/7,4) = 128.5714.
        $asset = $this->asset(1000, 100, 7);
        $asset->accumulated_depreciation = 771.4284; // six months of straight-line
        $charge = $asset->monthlyDepreciation();

        // Final month charges the exact remainder (128.5716), not the rounded straight
        // figure, so it clears to the base in exactly 7 months — not an extra sub-cent 8th.
        $this->assertEqualsWithDelta(128.5716, $charge, 0.0001);
        $this->assertEqualsWithDelta(900.0, 771.4284 + $charge, 0.0001);
    }

    public function test_a_disposed_asset_cannot_be_disposed_again(): void
    {
        $asset = $this->asset(1000, 0, 10);
        $service = app(FixedAssetService::class);
        $service->acquire($asset, (int) Account::where('code', '1102')->value('id'));
        $service->dispose($asset, 500, $this->date, (int) Account::where('code', '1102')->value('id'));

        $this->expectException(FixedAssetException::class);
        $service->dispose($asset->refresh(), 100, $this->date, (int) Account::where('code', '1102')->value('id'));
    }

    public function test_duplicate_period_depreciation_is_blocked(): void
    {
        $asset = $this->asset(1200, 0, 12);
        $service = app(FixedAssetService::class);
        $service->acquire($asset, (int) Account::where('code', '1102')->value('id'));
        $service->runDepreciation($this->year, $this->month);

        $this->expectException(FixedAssetException::class);
        $service->runDepreciation($this->year, $this->month);
    }
}
