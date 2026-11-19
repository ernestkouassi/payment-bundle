<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\Paystack;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class PaystackConfig
{
    public const DEFAULT_API_BASE_URL = 'https://api.paystack.co';

    public const DEFAULT_TIMEOUT = 10.0;

    public string $apiBaseUrl;

    public function __construct(
        public string $secretKey,
        string $apiBaseUrl = self::DEFAULT_API_BASE_URL,
        public ?string $defaultCallbackUrl = null,
        /** Timeout in seconds of each HTTP call to the Paystack API */
        public float $timeout = self::DEFAULT_TIMEOUT,
        /** Webhook signing secret ("whsec_..."). When set, webhooks must carry a valid "Paystack-Signature" header */
        public ?string $webhookSecret = null,
    ) {
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
    }
}
