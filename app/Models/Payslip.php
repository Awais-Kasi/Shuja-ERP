<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'payroll_run_id', 'employee_id',
        'basic', 'house_rent', 'medical', 'conveyance', 'other_allowance', 'gross_earnings',
        'lop_days', 'loss_of_pay',
        'income_tax', 'eobi', 'provident_fund', 'other_deduction', 'total_deductions',
        'employer_eobi', 'employer_pf', 'net_pay', 'paid_amount', 'status',
    ];

    protected function casts(): array
    {
        return [
            'basic' => 'decimal:4',
            'house_rent' => 'decimal:4',
            'medical' => 'decimal:4',
            'conveyance' => 'decimal:4',
            'other_allowance' => 'decimal:4',
            'gross_earnings' => 'decimal:4',
            'lop_days' => 'decimal:2',
            'loss_of_pay' => 'decimal:4',
            'income_tax' => 'decimal:4',
            'eobi' => 'decimal:4',
            'provident_fund' => 'decimal:4',
            'other_deduction' => 'decimal:4',
            'total_deductions' => 'decimal:4',
            'employer_eobi' => 'decimal:4',
            'employer_pf' => 'decimal:4',
            'net_pay' => 'decimal:4',
            'paid_amount' => 'decimal:4',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function outstanding(): float
    {
        return round((float) $this->net_pay - (float) $this->paid_amount, 4);
    }
}
