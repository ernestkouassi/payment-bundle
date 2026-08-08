<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\CentralBill;

use E2K\PaymentBundle\Enum\PaymentStatus;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final class CentralBillStatus
{
    public const PENDING = 'PENDING';
    public const PROCESSING = 'PROCESSING';
    public const COMPLETED = 'COMPLETED';
    public const CANCELED = 'CANCELED';
    public const REFUSED = 'REFUSED';
    public const FAILED = 'FAILED';
    public const REVERSED = 'REVERSED';
    public const NEEDS_MERCHANT_VALIDATION = 'NEEDS_MERCHANT_VALIDATION';

    public static function toPaymentStatus(string $centralBillStatus): PaymentStatus
    {
        return match ($centralBillStatus) {
            self::COMPLETED => PaymentStatus::SUCCESS,
            self::PENDING, self::PROCESSING, self::NEEDS_MERCHANT_VALIDATION => PaymentStatus::PENDING,
            self::CANCELED, self::REVERSED => PaymentStatus::CANCELLED,
            default => PaymentStatus::FAILED,
        };
    }
}
