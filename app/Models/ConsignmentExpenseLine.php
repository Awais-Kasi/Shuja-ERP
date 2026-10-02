<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentExpenseLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'consignment_expense_id', 'expense_type', 'amount',
        'capitalise', 'expense_account_id', 'description',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'capitalise' => 'boolean'];
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
