<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Enum;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
enum Currency: string
{
    case XOF = 'XOF';
    case XAF = 'XAF';
    case EUR = 'EUR';
    case USD = 'USD';
    case GHS = 'GHS';
    case NGN = 'NGN';

    public function decimals(): int
    {
        return match ($this) {
            self::XOF, self::XAF => 0,
            default => 2,
        };
    }
}
