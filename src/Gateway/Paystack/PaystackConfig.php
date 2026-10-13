<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\Paystack;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class PaystackConfig
{
    public function __construct(
        public string $secretKey,
        public string $apiBaseUrl,
        public ?string $defaultCallbackUrl = null,
    ) {
    }
}
