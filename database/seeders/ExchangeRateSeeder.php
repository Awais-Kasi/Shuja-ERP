<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ExchangeRate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ExchangeRateSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        $today = Carbon::now()->toDateString();
        $prior = Carbon::now()->subDays(30)->toDateString();

        // Base per 1 unit of the quote currency. A prior + current pair for USD lets the
        // FX revaluation post a real closing-rate movement; the rest give the forms a
        // sensible default rate to prefill.
        $rates = [
            ['quote_code' => 'USD', 'rate' => 278.5000, 'rate_date' => $prior],
            ['quote_code' => 'USD', 'rate' => 283.7500, 'rate_date' => $today],
            ['quote_code' => 'AED', 'rate' => 77.2500, 'rate_date' => $today],
            ['quote_code' => 'EUR', 'rate' => 305.2000, 'rate_date' => $today],
            ['quote_code' => 'GBP', 'rate' => 358.4000, 'rate_date' => $today],
            ['quote_code' => 'SAR', 'rate' => 75.6000, 'rate_date' => $today],
            ['quote_code' => 'CNY', 'rate' => 39.1000, 'rate_date' => $today],
        ];

        foreach ($rates as $r) {
            ExchangeRate::firstOrCreate(
                ['company_id' => $company->id, 'base_code' => 'PKR', 'quote_code' => $r['quote_code'], 'rate_date' => $r['rate_date']],
                ['rate' => $r['rate'], 'source' => 'seed'],
            );
        }
    }
}
