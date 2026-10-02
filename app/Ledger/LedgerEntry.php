<?php

namespace App\Ledger;

use Illuminate\Database\Eloquent\Model;

/**
 * A balanced set of instructions to be posted as one journal.
 */
final class LedgerEntry
{
    /**
     * @param  array<int, LedgerLine>  $lines
     */
    public function __construct(
        public string $entryDate,
        public array $lines,
        public string $type = 'manual',
        public ?string $memo = null,
        public ?string $reference = null,
        public ?Model $source = null,
    ) {}
}
