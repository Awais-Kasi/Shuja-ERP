<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class NumberSequence extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'key', 'prefix', 'suffix', 'padding', 'next_number', 'reset_yearly',
    ];

    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'next_number' => 'integer',
            'reset_yearly' => 'boolean',
        ];
    }

    /**
     * Atomically allocate and format the next document number for a key
     * within the current company. Row is locked so concurrent requests
     * never receive the same number.
     */
    public static function next(string $key): string
    {
        $companyId = app(TenantManager::class)->id();

        return DB::transaction(function () use ($key, $companyId) {
            $seq = static::query()
                ->where('company_id', $companyId)
                ->where('key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            $number = $seq->next_number;
            $seq->next_number = $number + 1;
            $seq->save();

            return $seq->prefix.str_pad((string) $number, $seq->padding, '0', STR_PAD_LEFT).$seq->suffix;
        });
    }
}
