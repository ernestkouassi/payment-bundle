<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Enum;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
enum PaymentStatus: string
{
    case PENDING = 'pending';
    case INITIATED = 'initiated';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
    case EXPIRED = 'expired';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::SUCCESS,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
            self::EXPIRED,
        ], true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::SUCCESS;
    }
}
