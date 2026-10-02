<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'receivable_account_id', 'code', 'name', 'legal_name',
        'tax_registration_no', 'email', 'phone', 'address', 'currency',
        'credit_limit', 'payment_terms_days', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'credit_limit' => 'decimal:4', 'payment_terms_days' => 'integer'];
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }
}
