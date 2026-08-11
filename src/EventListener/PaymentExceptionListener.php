<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\EventListener;

use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\GatewayNotFoundException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\Exception\Reason;
use E2K\PaymentBundle\Http\ApiErrorResponse;
use E2K\PaymentBundle\Http\PaymentErrorCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final class PaymentExceptionListener implements EventSubscriberInterface
{
    /**
     * @var string the prefix for payment-related routes
     */
    private const PAYMENT_ROUTE_PREFIX = '/payments/';

    /**
     * @var string the domain for translation keys
     */
    private const TRANSLATION_DOMAIN = 'E2KPaymentBundle';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KernelInterface $kernel,
        private readonly TranslatorInterface $translator,
        private readonly ?string $paymentRoutePrefix = self::PAYMENT_ROUTE_PREFIX,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => [['onKernelException', 10]],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        if (! str_starts_with($request->getPathInfo(), $this->paymentRoutePrefix)) {
            return;
        }

        $response = match (true) {
            $exception instanceof InvalidCallbackException => $this->handleCallbackException($exception),
            $exception instanceof GatewayNotFoundException => $this->handleNotFoundException($exception),
            $exception instanceof GatewayException => $this->handleGatewayException($exception),
            default => null,
        };

        if (null === $response) {
            return;
        }

        $event->setResponse($response);
        $event->stopPropagation();
    }

    private function handleGatewayException(GatewayException $e): ApiErrorResponse
    {
        [$httpStatus, $errorCode] = $this->resolveGatewayMeta($e);

        $this->logger->error('Payment gateway error', [
            'gateway' => $e->getGatewayName(),
            'gateway_code' => $e->getGatewayCode(),
            'message' => $e->getMessage(),
            'context' => $e->getContext(),
            'exception' => $e,
        ]);

        return new ApiErrorResponse(
            code: $errorCode,
            message: $this->clientMessage($e, $httpStatus),
            httpStatus: $httpStatus,
            details: $this->debugDetails($e),
            debug: $this->isDebug(),
        );
    }

    private function handleCallbackException(InvalidCallbackException $e): ApiErrorResponse
    {
        [$httpStatus, $errorCode] = $this->resolveCallbackMeta($e);

        $logLevel = Reason::REPLAY_DETECTED === $e->getReason() ? 'warning' : 'error';
        $this->logger->$logLevel('IPN callback rejected', [
            'reason' => $e->getReason()->name,
            'message' => $e->getMessage(),
            'context' => $e->getDebugContext(),
        ]);

        return new ApiErrorResponse(
            code: $errorCode,
            message: $this->clientMessage($e, $httpStatus),
            httpStatus: $httpStatus,
            details: $this->debugDetails($e),
            debug: $this->isDebug(),
        );
    }

    private function handleNotFoundException(GatewayNotFoundException $e): ApiErrorResponse
    {
        $this->logger->critical('Payment gateway not found', [
            'requested' => $e->getRequestedName(),
            'available' => $e->getAvailableGateways(),
            'message' => $e->getMessage(),
        ]);

        $errorCode = $e->getPrevious() instanceof \RuntimeException ? PaymentErrorCode::GATEWAY_NOT_CONFIGURED : PaymentErrorCode::GATEWAY_NOT_FOUND;

        return new ApiErrorResponse(
            code: $errorCode,
            message: $this->trans('error.service_unavailable'),
            httpStatus: Response::HTTP_INTERNAL_SERVER_ERROR,
            details: $this->debugDetails($e),
            debug: $this->isDebug(),
        );
    }

    /**
     * @return array{int, PaymentErrorCode}
     */
    private function resolveGatewayMeta(GatewayException $e): array
    {
        return match (true) {
            str_contains($e->getMessage(), 'misconfigured') => [Response::HTTP_INTERNAL_SERVER_ERROR, PaymentErrorCode::GATEWAY_MISCONFIGURED],
            str_contains($e->getMessage(), 'HTTP error') => [Response::HTTP_BAD_GATEWAY, PaymentErrorCode::GATEWAY_HTTP_ERROR],
            str_contains($e->getMessage(), 'Network error') => [Response::HTTP_BAD_GATEWAY, PaymentErrorCode::GATEWAY_NETWORK_ERROR],
            str_contains($e->getMessage(), 'declined') => [Response::HTTP_UNPROCESSABLE_ENTITY, PaymentErrorCode::GATEWAY_DECLINED],
            str_contains($e->getMessage(), 'Unexpected response') => [Response::HTTP_BAD_GATEWAY, PaymentErrorCode::GATEWAY_UNEXPECTED],
            default => [Response::HTTP_INTERNAL_SERVER_ERROR, PaymentErrorCode::INTERNAL_ERROR],
        };
    }

    /**
     * @return array{int, PaymentErrorCode}
     */
    private function resolveCallbackMeta(InvalidCallbackException $e): array
    {
        return match ($e->getReason()) {
            Reason::INVALID_SIGNATURE => [Response::HTTP_BAD_REQUEST, PaymentErrorCode::CALLBACK_INVALID_SIGNATURE],
            Reason::MISSING_SIGNATURE => [Response::HTTP_BAD_REQUEST, PaymentErrorCode::CALLBACK_MISSING_SIGNATURE],
            Reason::UNSUPPORTED_ALGORITHM => [Response::HTTP_BAD_REQUEST, PaymentErrorCode::CALLBACK_BAD_ALGORITHM],
            Reason::MALFORMED_PAYLOAD => [Response::HTTP_BAD_REQUEST, PaymentErrorCode::CALLBACK_MALFORMED],
            Reason::MISSING_FIELD => [Response::HTTP_BAD_REQUEST, PaymentErrorCode::CALLBACK_MISSING_FIELD],
            Reason::EXPIRED_REQUEST => [Response::HTTP_BAD_REQUEST, PaymentErrorCode::CALLBACK_EXPIRED],
            Reason::REPLAY_DETECTED => [Response::HTTP_OK, PaymentErrorCode::CALLBACK_REPLAY],
            Reason::UNKNOWN => [Response::HTTP_BAD_REQUEST, PaymentErrorCode::INTERNAL_ERROR],
        };
    }

    private function safeMessage(\Throwable $e, int $httpStatus): string
    {
        if ($httpStatus >= 500 && ! $this->isDebug()) {
            return 'An error occurred while processing the payment. Please try again.';
        }

        return $e->getMessage();
    }

    /**
     * Returns the message exposed to the client. In production: generic translated message for 5xx errors.
     * In debug: technical message of the exception.
     */
    private function clientMessage(\Throwable $e, int $httpStatus): string
    {
        if ($httpStatus >= 500 && ! $this->kernel->isDebug()) {
            return $this->trans('error.internal');
        }

        return $e->getMessage();
    }

    private function debugDetails(\Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    private function isDebug(): bool
    {
        return $this->kernel->isDebug();
    }

    /**
     * Translates a message key using the translator service within the specified translation domain.
     *
     * @param array<string, mixed> $parameters Translation parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, self::TRANSLATION_DOMAIN);
    }
}
