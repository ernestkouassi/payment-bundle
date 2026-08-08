<?php

declare(strict_types=1);

namespace E2K\PaymentBundle;

use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\DTO\PaymentResponse;
use E2K\PaymentBundle\Exception\GatewayException;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
interface PaymentInitiatorInterface
{
    /**
     * Initialises a payment with the gateway.
     *
     * @throws GatewayException If the gateway returns an error.
     */
    public function initiate(PaymentRequest $request): PaymentResponse;
}
