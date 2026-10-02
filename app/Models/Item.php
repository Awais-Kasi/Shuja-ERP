<?php

namespace App\Models;

use App\Enums\ValuationMethod;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'uom_id', 'code', 'name', 'description', 'type', 'valuation_method',
        'inventory_account_id', 'cogs_account_id', 'income_account_id',
        'tracks_inventory', 'is_purchasable', 'is_sellable', 'is_active',
        'reorder_level', 'standard_cost',
    ];

    protected function casts(): array
    {
        return [
            'tracks_inventory' => 'boolean',
            'is_purchasable' => 'boolean',
            'is_sellable' => 'boolean',
            'is_active' => 'boolean',
            'reorder_level' => 'decimal:4',
            'standard_cost' => 'decimal:4',
        ];
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** Effective valuation method, falling back to the company default. */
    public function valuationMethod(): ValuationMethod
    {
        if ($this->valuation_method) {
            return ValuationMethod::from($this->valuation_method);
        }

        $default = app(TenantManager::class)->get()?->default_valuation_method ?? 'weighted_average';

        return ValuationMethod::from($default);
    }
}
