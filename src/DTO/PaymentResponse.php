<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\DTO;

use E2K\PaymentBundle\Enum\PaymentStatus;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class PaymentResponse
{
    public function __construct(
        public PaymentStatus $status,
        /** Internal reference */
        public string $invoiceId,
        /** Transaction ID on the gateway side */
        public ?string $gatewayTransactionId = null,
        /** Redirect URL for the user */
        public ?string $redirectUrl = null,
        /** Token / payment session on the gateway side */
        public ?string $gatewayToken = null,
        /** Raw data returned by the gateway */
        public array $rawResponse = [],
    ) {}

    public function requiresRedirect(): bool
    {
        return null !== $this->redirectUrl;
    }
}
