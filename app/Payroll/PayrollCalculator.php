<?php

namespace App\Payroll;

use App\Models\Employee;

/**
 * Derives statutory payroll figures for a single employee-month from the employee's
 * standing salary structure and a {@see PayrollConfig} (rates + tax slabs). The config
 * defaults to the built-in FY2024-25 values, so `new PayrollCalculator` still works;
 * pass a company-specific config to honour per-company overrides.
 */
class PayrollCalculator
{
    private readonly PayrollConfig $config;

    public function __construct(?PayrollConfig $config = null)
    {
        $this->config = $config ?? PayrollConfig::defaults();
    }

    /**
     * @return array{gross:float,eobi:float,employer_eobi:float,provident_fund:float,employer_pf:float,income_tax:float}
     */
    public function forEmployee(Employee $employee): array
    {
        $gross = $employee->grossEarnings();
        $basic = round((float) $employee->basic_salary, 4);

        $eobi = round($this->config->eobiWageBase * $this->config->eobiEmployeeRate, 4);
        $employerEobi = round($this->config->eobiWageBase * $this->config->eobiEmployerRate, 4);

        $pf = round($basic * $this->config->pfRate, 4);
        $employerPf = $pf;

        $incomeTax = $this->monthlyIncomeTax($gross);

        return [
            'gross' => $gross,
            'eobi' => $eobi,
            'employer_eobi' => $employerEobi,
            'provident_fund' => $pf,
            'employer_pf' => $employerPf,
            'income_tax' => $incomeTax,
        ];
    }

    /**
     * Monthly withholding = annual tax on the annualised gross, divided by 12.
     */
    public function monthlyIncomeTax(float $monthlyGross): float
    {
        $annual = max(0.0, $monthlyGross) * 12;
        $tax = 0.0;

        foreach ($this->config->taxSlabs as [$lower, $base, $rate]) {
            if ($annual > $lower) {
                $tax = $base + ($annual - $lower) * $rate;
            }
        }

        return round($tax / 12, 4);
    }
}
