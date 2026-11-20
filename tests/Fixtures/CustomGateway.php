<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Fixtures;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\CallbackResult;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\DTO\PaymentResponse;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\PaymentGatewayInterface;

/**
 * Minimal third-party gateway, used to check autoconfiguration.
 *
 * @internal
 */
final class CustomGateway implements PaymentGatewayInterface
{
    public static function getName(): string
    {
        return 'custom';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getTransactionStatus(string $gatewayTransactionId): PaymentStatus
    {
        return PaymentStatus::PENDING;
    }

    public function initiate(PaymentRequest $request): PaymentResponse
    {
        return new PaymentResponse(PaymentStatus::INITIATED, $request->invoiceId);
    }

    public function validateSignature(CallbackPayload $payload): void
    {
    }

    public function handle(CallbackPayload $payload): CallbackResult
    {
        return new CallbackResult(PaymentStatus::SUCCESS, 'invoice', 'transaction');
    }
}
