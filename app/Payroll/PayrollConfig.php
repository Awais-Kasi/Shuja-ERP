<?php

namespace App\Payroll;

use App\Models\PayrollSetting;
use App\Models\PayrollTaxSlab;

/**
 * Immutable snapshot of a company's payroll statutory configuration — the EOBI/PF
 * rates and the annual income-tax slabs. Built either from the built-in FY2024-25
 * defaults or from the per-company payroll_settings / payroll_tax_slabs rows, so the
 * PayrollCalculator stays pure and the rates live in one editable place.
 */
final class PayrollConfig
{
    /** Built-in defaults (Pakistan FY2024-25 salaried), used when a company has no rows. */
    public const DEFAULT_EOBI_WAGE_BASE = 37000.0;

    public const DEFAULT_EOBI_EMPLOYEE_RATE = 0.01;

    public const DEFAULT_EOBI_EMPLOYER_RATE = 0.05;

    public const DEFAULT_PF_RATE = 0.0833;

    /** @var array<int, array{0:float,1:float,2:float}> [lower_bound, base_tax, marginal_rate] */
    public const DEFAULT_TAX_SLABS = [
        [0, 0, 0.00],
        [600000, 0, 0.05],
        [1200000, 30000, 0.15],
        [2200000, 180000, 0.25],
        [3200000, 430000, 0.30],
        [4100000, 700000, 0.35],
    ];

    /**
     * @param  array<int, array{0:float,1:float,2:float}>  $taxSlabs  sorted ascending by lower bound
     */
    public function __construct(
        public readonly float $eobiWageBase,
        public readonly float $eobiEmployeeRate,
        public readonly float $eobiEmployerRate,
        public readonly float $pfRate,
        public readonly array $taxSlabs,
    ) {}

    public static function defaults(): self
    {
        return new self(
            self::DEFAULT_EOBI_WAGE_BASE,
            self::DEFAULT_EOBI_EMPLOYEE_RATE,
            self::DEFAULT_EOBI_EMPLOYER_RATE,
            self::DEFAULT_PF_RATE,
            self::DEFAULT_TAX_SLABS,
        );
    }

    /**
     * Load a company's configuration, falling back to the built-in defaults for any
     * part not configured. Queries are company-scoped via the models' global scope.
     */
    public static function forCompany(?int $companyId = null): self
    {
        $setting = PayrollSetting::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->first();

        $slabRows = PayrollTaxSlab::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('lower_bound')
            ->get(['lower_bound', 'base_tax', 'rate']);

        $slabs = $slabRows->isNotEmpty()
            ? $slabRows->map(fn ($s) => [(float) $s->lower_bound, (float) $s->base_tax, (float) $s->rate])->all()
            : self::DEFAULT_TAX_SLABS;

        return new self(
            $setting ? (float) $setting->eobi_wage_base : self::DEFAULT_EOBI_WAGE_BASE,
            $setting ? (float) $setting->eobi_employee_rate : self::DEFAULT_EOBI_EMPLOYEE_RATE,
            $setting ? (float) $setting->eobi_employer_rate : self::DEFAULT_EOBI_EMPLOYER_RATE,
            $setting ? (float) $setting->pf_rate : self::DEFAULT_PF_RATE,
            $slabs,
        );
    }
}
