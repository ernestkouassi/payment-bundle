<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\CentralBill;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class CentralBillConfig
{
    /** Path of the CentralBill REST processor, appended to the configured API host. */
    public const API_PATH = '/processor/v1/rest';

    /** Timeout of the payment pre-creation: the customer is waiting behind it. */
    public const DEFAULT_INITIATE_TIMEOUT = 6.0;

    /** Timeout of the payment lookup (polling): nobody is waiting behind it. */
    public const DEFAULT_STATUS_TIMEOUT = 15.0;

    /**
     * Base URL of the REST processor (host + API_PATH), or null when the server-side API is not used.
     */
    public ?string $apiBaseUrl;

    /**
     * @param string|null $apiBaseUrl Host of the CentralBill API (e.g. https://api.centralbill.app). When set, payments
     *                                are pre-created server side and can be polled. API_PATH is appended unless present.
     */
    public function __construct(
        public string $applicationId,
        public string $applicationSecret,
        public string $webPayBaseUrl,
        public ?string $defaultCallbackUrl = null,
        public ?string $defaultRedirectUrl = null,
        /** Maximum age in seconds of a signed IPN "Date" header. 0 disables the check. */
        public int $callbackTolerance = CentralBillSignatureVerifier::DEFAULT_TOLERANCE,
        ?string $apiBaseUrl = null,
        public float $initiateTimeout = self::DEFAULT_INITIATE_TIMEOUT,
        public float $statusTimeout = self::DEFAULT_STATUS_TIMEOUT,
    ) {
        $this->apiBaseUrl = self::normalizeApiBaseUrl($apiBaseUrl);
    }

    public function isApiEnabled(): bool
    {
        return null !== $this->apiBaseUrl;
    }

    private static function normalizeApiBaseUrl(?string $apiBaseUrl): ?string
    {
        $apiBaseUrl = rtrim(trim((string) $apiBaseUrl), '/');

        if ('' === $apiBaseUrl) {
            return null;
        }

        // Tolerate a host that already carries the path, so that it does not end up twice.
        return str_ends_with($apiBaseUrl, self::API_PATH) ? $apiBaseUrl : $apiBaseUrl.self::API_PATH;
    }
}
