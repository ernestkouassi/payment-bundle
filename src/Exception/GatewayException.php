<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Exception;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
class GatewayException extends \RuntimeException
{
    public function __construct(
        private readonly string $gatewayName,
        string $message,
        private readonly ?int $gatewayCode = null,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function configurationError(string $gatewayName, string $detail): self
    {
        return new self(
            gatewayName: $gatewayName,
            message: \sprintf('[%s] Gateway misconfigured: %s', $gatewayName, $detail),
        );
    }

    public static function httpError(
        string $gatewayName,
        int $httpStatus,
        string $responseBody,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            gatewayName: $gatewayName,
            message: \sprintf('[%s] HTTP error %d from gateway.', $gatewayName, $httpStatus),
            gatewayCode: $httpStatus,
            context: ['response_body' => $responseBody],
            previous: $previous,
        );
    }

    public static function unexpectedResponse(
        string $gatewayName,
        string $detail,
        array $rawResponse = [],
    ): self {
        return new self(
            gatewayName: $gatewayName,
            message: \sprintf('[%s] Unexpected response: %s', $gatewayName, $detail),
            context: ['raw_response' => $rawResponse],
        );
    }

    public static function networkError(string $gatewayName, \Throwable $cause): self
    {
        return new self(
            gatewayName: $gatewayName,
            message: \sprintf('[%s] Network error: %s', $gatewayName, $cause->getMessage()),
            previous: $cause,
        );
    }

    public static function declined(
        string $gatewayName,
        string $reason,
        ?int $gatewayCode = null,
        array $context = [],
    ): self {
        return new self(
            gatewayName: $gatewayName,
            message: \sprintf('[%s] Payment declined: %s', $gatewayName, $reason),
            gatewayCode: $gatewayCode,
            context: $context,
        );
    }

    public function getGatewayName(): string
    {
        return $this->gatewayName;
    }

    public function getGatewayCode(): ?int
    {
        return $this->gatewayCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
