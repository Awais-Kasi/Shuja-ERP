<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'eobi_wage_base', 'eobi_employee_rate', 'eobi_employer_rate', 'pf_rate',
    ];

    protected function casts(): array
    {
        return [
            'eobi_wage_base' => 'decimal:4',
            'eobi_employee_rate' => 'decimal:6',
            'eobi_employer_rate' => 'decimal:6',
            'pf_rate' => 'decimal:6',
        ];
    }
}
