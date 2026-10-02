<?php

namespace App\Ledger;

/**
 * A single debit or credit instruction handed to the PostingEngine.
 * Exactly one of debit / credit should be positive.
 */
final class LedgerLine
{
    public function __construct(
        public int $accountId,
        public float|string $debit = 0,
        public float|string $credit = 0,
        public ?int $costCenterId = null,
        public ?string $description = null,
        public ?string $currency = null,
        public float|string $fxRate = 1,
        public ?string $partyType = null,
        public ?int $partyId = null,
    ) {}

    public static function debit(int $accountId, float|string $amount, ?string $description = null, ?int $costCenterId = null): self
    {
        return new self($accountId, debit: $amount, description: $description, costCenterId: $costCenterId);
    }

    public static function credit(int $accountId, float|string $amount, ?string $description = null, ?int $costCenterId = null): self
    {
        return new self($accountId, credit: $amount, description: $description, costCenterId: $costCenterId);
    }
}
