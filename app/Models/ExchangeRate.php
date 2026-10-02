<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'base_code', 'quote_code', 'rate', 'rate_date', 'source',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:10',
            'rate_date' => 'date',
        ];
    }
}
