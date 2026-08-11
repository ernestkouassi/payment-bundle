<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\CentralBill;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class CentralBillConfig
{
    public function __construct(
        public string $applicationId,
        public string $applicationSecret,
        public string $webPayBaseUrl,
        public ?string $defaultCallbackUrl = null,
        public ?string $defaultRedirectUrl = null,
    ) {
    }
}
