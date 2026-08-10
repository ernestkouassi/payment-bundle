<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\DTO;

use E2K\PaymentBundle\Enum\Currency;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class PaymentRequest
{
    public function __construct(
        /** Amount in whole units (e.g., 5000 for 5000 XOF) */
        public int $amount,
        public Currency $currency,
        /** Internal unique reference for the order / transaction */
        public string $invoiceId,
        /** Email of the payer */
        public string $customerId,
        public ?string $customerName,
        public \DateTimeInterface $invoiceIssuedAt,
        public ?\DateTimeInterface $invoicedDueDate = null,
        public ?\DateTimeInterface $invoiceExpiresAt = null,
        /** Redirect URL after payment (front-end) */
        public ?string $returnUrl = null,
        /** Callback / webhook URL (server) */
        public ?string $callbackUrl = null,
        /** Free metadata transmitted to the gateway  */
        public array $metadata = [],
        public ?string $description = null,
    ) {}
}
