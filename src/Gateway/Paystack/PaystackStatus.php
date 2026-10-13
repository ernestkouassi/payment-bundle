<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\Paystack;

use E2K\PaymentBundle\Enum\PaymentStatus;

/**
 * Mapping of native Paystack statuses to normalized PaymentStatus.
 *
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final class PaystackStatus
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const ABANDONED = 'abandoned';

    public const PENDING = 'pending';

    public const ONGOING = 'ongoing';

    /**
     * Paystack webhook events. Only charge.success implies a successful payment.
     */
    public const EVENT_CHARGE_SUCCESS = 'charge.success';

    /**
     * Converts a Paystack transaction status into a normalized PaymentStatus.
     */
    public static function toPaymentStatus(string $paystackStatus): PaymentStatus
    {
        return match ($paystackStatus) {
            self::SUCCESS => PaymentStatus::SUCCESS,
            self::PENDING, self::ONGOING => PaymentStatus::PENDING,
            self::ABANDONED => PaymentStatus::CANCELLED,
            default => PaymentStatus::FAILED,
        };
    }
}
