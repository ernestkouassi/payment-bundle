<?php

declare(strict_types=1);

namespace E2K\PaymentBundle;

use E2K\PaymentBundle\DTO\CallbackResult;
use E2K\PaymentBundle\Exception\GatewayException;

/**
 * Optional contract for gateways able to read back a payment from the provider (polling / reconciliation).
 *
 * Unlike PaymentGatewayInterface::getTransactionStatus(), which only returns a status, the result carries the
 * invoice, the amount and the raw payload, so that the application can check that the payment really belongs to
 * the order it is about to settle (invoice id, customer, amount) before acting on it. It uses the same DTO as the
 * IPN/webhook, so both paths can share the same handling code.
 *
 *     if ($gateway instanceof PaymentLookupInterface && null !== $paymentId) {
 *         $result = $gateway->fetchPayment($paymentId);
 *     }
 *
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
interface PaymentLookupInterface
{
    /**
     * Reads a payment back from the provider.
     *
     * @param string $gatewayPaymentId Identifier returned by initiate() in PaymentResponse::$gatewayTransactionId
     *
     * @throws GatewayException If the provider cannot be reached or its answer cannot be interpreted;
     *                          a technical failure is never reported as a payment status
     */
    public function fetchPayment(string $gatewayPaymentId): CallbackResult;
}
