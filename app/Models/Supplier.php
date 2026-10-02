<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Supplier extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'payable_account_id', 'code', 'name', 'legal_name',
        'tax_registration_no', 'email', 'phone', 'address', 'currency',
        'payment_terms_days', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_terms_days' => 'integer'];
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }
}
