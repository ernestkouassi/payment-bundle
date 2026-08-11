<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Resolver;

use E2K\PaymentBundle\Exception\GatewayNotFoundException;
use E2K\PaymentBundle\PaymentGatewayInterface;
use E2K\PaymentBundle\Registry\PaymentGatewayRegistry;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class ActiveGatewayResolver
{
    public function __construct(
        private PaymentGatewayRegistry $registry,
        /** Inject from e2k payment.yaml: e2k payment.default gateway */
        private string $defaultGatewayName,
    ) {
    }

    /**
     * Returns the active gateway. If $name is provided, it uses that gateway; otherwise, the default gateway.
     *
     * @throws GatewayNotFoundException
     */
    public function resolve(?string $name = null): PaymentGatewayInterface
    {
        $targetName = $name ?? $this->defaultGatewayName;

        $gateway = $this->registry->get($targetName);

        if (! $gateway->isConfigured()) {
            throw GatewayNotFoundException::notConfigured($targetName);
        }

        return $gateway;
    }
}
