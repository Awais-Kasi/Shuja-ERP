<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'cost_center_id', 'salary_expense_account_id', 'code', 'name',
        'designation', 'department', 'employment_type', 'payment_method', 'cnic', 'eobi_no',
        'bank_name', 'bank_account_no', 'phone',
        'basic_salary', 'house_rent', 'medical', 'conveyance', 'other_allowance',
        'date_joined', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:4',
            'house_rent' => 'decimal:4',
            'medical' => 'decimal:4',
            'conveyance' => 'decimal:4',
            'other_allowance' => 'decimal:4',
            'date_joined' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function grossEarnings(): float
    {
        return round((float) $this->basic_salary + (float) $this->house_rent + (float) $this->medical
            + (float) $this->conveyance + (float) $this->other_allowance, 4);
    }
}
