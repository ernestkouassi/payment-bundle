<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Exception;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
class GatewayNotFoundException extends \InvalidArgumentException
{
    public function __construct(
        private readonly string $requestedName,
        private readonly array  $availableGateways = [],
        ?\Throwable             $previous          = null,
    ) {
        parent::__construct($this->buildMessage(), 0, $previous);
    }

    public static function forName(string $name, array $available = []): self
    {
        return new self(requestedName: $name, availableGateways: $available);
    }

    public static function noDefault(string $envValue): self
    {
        return new self(
            requestedName: $envValue,
            availableGateways: [],
            previous: new \LogicException(
                sprintf(
                    'PAYMENT_DEFAULT_GATEWAY="%s" does not match any registered gateway. '
                    . 'Check your e2k_payment.yaml or the services implementing PaymentGatewayInterface.',
                    $envValue,
                )
            ),
        );
    }

    public static function notConfigured(string $name): self
    {
        return new self(
            requestedName: $name,
            previous: new \RuntimeException(
                sprintf('Gateway "%s" was found but is not properly configured (missing credentials?).', $name)
            ),
        );
    }

    public function getRequestedName(): string    { return $this->requestedName; }
    public function getAvailableGateways(): array { return $this->availableGateways; }

    private function buildMessage(): string
    {
        $msg = sprintf('Payment gateway "%s" not found.', $this->requestedName);

        if (!empty($this->availableGateways)) {
            $msg .= sprintf(' Available: [%s].', implode(', ', $this->availableGateways));
        } else {
            $msg .= ' No gateways registered — did you implement PaymentGatewayInterface?';
        }

        return $msg;
    }
}
