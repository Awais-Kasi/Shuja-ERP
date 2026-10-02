<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /** Side on which this account type normally carries a positive balance. */
    public function normalBalance(): string
    {
        return match ($this) {
            self::Asset, self::Expense => 'debit',
            self::Liability, self::Equity, self::Income => 'credit',
        };
    }

    public function isDebitNormal(): bool
    {
        return $this->normalBalance() === 'debit';
    }

    /** Balance-sheet accounts carry forward; income-statement accounts close each year. */
    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
