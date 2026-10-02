<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPaymentLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'payroll_payment_id', 'payslip_id', 'employee_id', 'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PayrollPayment::class, 'payroll_payment_id');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
