<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\DTO;

use E2K\PaymentBundle\Enum\PaymentStatus;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class CallbackResult
{
    public function __construct(
        public PaymentStatus $status,
        /** Internal reference of your system */
        public string $invoiceId,
        /** Transaction ID on the gateway side */
        public string $gatewayTransactionId,
        /** Confirmed amount by the gateway */
        public ?string $amount = null,
        /** Error message if failed */
        public ?string $errorMessage = null,
        /** Raw payload for audit / log */
        public array $rawPayload = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }
}
