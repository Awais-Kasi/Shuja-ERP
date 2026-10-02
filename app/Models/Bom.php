<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bom extends Model
{
    use BelongsToCompany;

    protected $table = 'boms';

    protected $fillable = ['company_id', 'item_id', 'code', 'name', 'output_qty', 'is_active'];

    protected function casts(): array
    {
        return ['output_qty' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class);
    }
}
