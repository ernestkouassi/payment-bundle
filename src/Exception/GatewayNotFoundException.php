<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Exception;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
class GatewayNotFoundException extends \InvalidArgumentException
{
    /**
     * @param list<string> $availableGateways
     */
    public function __construct(
        private readonly string $requestedName,
        private readonly array $availableGateways = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($this->buildMessage(), 0, $previous);
    }

    /**
     * @param list<string> $available
     */
    public static function forName(string $name, array $available = []): self
    {
        return new self(requestedName: $name, availableGateways: $available);
    }

    /**
     * @param list<string> $available
     */
    public static function noDefault(string $envValue, array $available = []): self
    {
        return new self(
            requestedName: $envValue,
            availableGateways: $available,
            previous: new \LogicException(
                \sprintf(
                    'PAYMENT_DEFAULT_GATEWAY="%s" does not match any registered gateway. '
                    .'Check your e2k_payment.yaml or the services implementing PaymentGatewayInterface.',
                    $envValue,
                ),
            ),
        );
    }

    public static function notConfigured(string $name): self
    {
        return new self(
            requestedName: $name,
            previous: new \RuntimeException(
                \sprintf('Gateway "%s" was found but is not properly configured (missing credentials?).', $name),
            ),
        );
    }

    /**
     * True when the gateway is unknown because of the application's own configuration
     * (default gateway missing, or gateway registered without credentials) rather than
     * because a client asked for a name that does not exist.
     */
    public function isServerMisconfiguration(): bool
    {
        return null !== $this->getPrevious();
    }

    public function isNotConfigured(): bool
    {
        return $this->getPrevious() instanceof \RuntimeException;
    }

    public function getRequestedName(): string
    {
        return $this->requestedName;
    }

    /**
     * @return list<string>
     */
    public function getAvailableGateways(): array
    {
        return $this->availableGateways;
    }

    private function buildMessage(): string
    {
        $msg = \sprintf('Payment gateway "%s" not found.', $this->requestedName);

        if (!empty($this->availableGateways)) {
            $msg .= \sprintf(' Available: [%s].', implode(', ', $this->availableGateways));
        } else {
            $msg .= ' No gateways registered — did you implement PaymentGatewayInterface?';
        }

        return $msg;
    }
}
