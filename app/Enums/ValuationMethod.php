<?php

namespace App\Enums;

enum ValuationMethod: string
{
    case Fifo = 'fifo';
    case WeightedAverage = 'weighted_average';

    public function label(): string
    {
        return match ($this) {
            self::Fifo => 'FIFO',
            self::WeightedAverage => 'Weighted Average',
        };
    }
}
