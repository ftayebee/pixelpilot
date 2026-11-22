<?php

namespace App\Services\Credits;

use App\Support\Money;
use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly string $required, public readonly string $available)
    {
        parent::__construct(sprintf(
            'This image can cost up to $%s and your balance is $%s. Add credits to continue.',
            Money::roundForDisplay(Money::max($required, '0.005')),
            Money::roundForDisplay($available),
        ));
    }
}
