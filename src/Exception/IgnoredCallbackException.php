<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Exception;

/**
 * Thrown by CallbackHandlerInterface::handle() for an authentic notification that the gateway
 * does not translate into a payment status (e.g. Paystack "transfer.success", "charge.dispute.create").
 *
 * The notification must be acknowledged (HTTP 200) so that the provider stops retrying it, and must
 * not change the state of the order. PaymentExceptionListener does so on payment routes; in your own
 * controller, catch it before the generic InvalidCallbackException:
 *
 *     try {
 *         $result = $gateway->handle($payload);
 *     } catch (IgnoredCallbackException) {
 *         return new JsonResponse(['status' => 'ignored']);
 *     }
 *
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final class IgnoredCallbackException extends InvalidCallbackException
{
    public static function unsupportedEvent(string $gatewayName, string $event): self
    {
        return new self(
            message: \sprintf('IPN ignored: %s event "%s" is not handled.', $gatewayName, $event),
            reason: Reason::IGNORED_EVENT,
            debugContext: ['gateway' => $gatewayName, 'event' => $event],
        );
    }
}
