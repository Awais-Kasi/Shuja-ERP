<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollTaxSlab extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'lower_bound', 'base_tax', 'rate',
    ];

    protected function casts(): array
    {
        return [
            'lower_bound' => 'decimal:4',
            'base_tax' => 'decimal:4',
            'rate' => 'decimal:6',
        ];
    }
}
