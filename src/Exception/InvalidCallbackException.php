<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Exception;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
class InvalidCallbackException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly Reason $reason = Reason::UNKNOWN,
        private readonly array $debugContext = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidSignature(string $detail = '', array $ctx = []): self
    {
        return new self(
            message: 'IPN rejected: invalid signature.'.($detail ? " ({$detail})" : ''),
            reason: Reason::INVALID_SIGNATURE,
            debugContext: $ctx,
        );
    }

    public static function missingSignature(): self
    {
        return new self(
            message: 'IPN rejected: Signature header is absent.',
            reason: Reason::MISSING_SIGNATURE,
        );
    }

    public static function unsupportedAlgorithm(string $algorithm): self
    {
        return new self(
            message: \sprintf('IPN rejected: unsupported algorithm "%s".', $algorithm),
            reason: Reason::UNSUPPORTED_ALGORITHM,
            debugContext: ['algorithm' => $algorithm],
        );
    }

    public static function malformedPayload(string $detail, ?\Throwable $previous = null): self
    {
        return new self(
            message: \sprintf('IPN rejected: malformed payload. %s', $detail),
            reason: Reason::MALFORMED_PAYLOAD,
            previous: $previous,
        );
    }

    public static function missingField(string $field): self
    {
        return new self(
            message: \sprintf('IPN rejected: missing required field "%s".', $field),
            reason: Reason::MISSING_FIELD,
            debugContext: ['field' => $field],
        );
    }

    public static function expiredRequest(string $date, int $toleranceSeconds): self
    {
        return new self(
            message: \sprintf(
                'IPN rejected: request date "%s" exceeds tolerance of %ds.',
                $date,
                $toleranceSeconds,
            ),
            reason: Reason::EXPIRED_REQUEST,
            debugContext: ['date' => $date, 'tolerance_seconds' => $toleranceSeconds],
        );
    }

    public static function replayDetected(string $transactionId): self
    {
        return new self(
            message: \sprintf('IPN rejected: transaction "%s" already processed (replay).', $transactionId),
            reason: Reason::REPLAY_DETECTED,
            debugContext: ['transaction_id' => $transactionId],
        );
    }

    public function getReason(): Reason
    {
        return $this->reason;
    }

    public function getDebugContext(): array
    {
        return $this->debugContext;
    }

    public function isRetryable(): bool
    {
        return match ($this->reason) {
            Reason::EXPIRED_REQUEST => true,
            default => false,
        };
    }
}
