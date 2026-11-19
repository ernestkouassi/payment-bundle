<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Registry;

use E2K\PaymentBundle\Exception\GatewayNotFoundException;
use E2K\PaymentBundle\PaymentGatewayInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
readonly class PaymentGatewayRegistry
{
    /**
     * @param ServiceProviderInterface<PaymentGatewayInterface> $locator Lazy service locator built by the bundle extension from the
     *                                                                   "e2k_payment.gateway" tag, indexed by each gateway's getName().
     *                                                                   A gateway is only instantiated on the first call to get().
     */
    public function __construct(
        private ServiceProviderInterface $locator,
    ) {
    }

    public function get(string $name): PaymentGatewayInterface
    {
        if (!$this->locator->has($name)) {
            throw GatewayNotFoundException::forName($name, $this->getAvailableNames());
        }

        return $this->locator->get($name);
    }

    public function has(string $name): bool
    {
        return $this->locator->has($name);
    }

    /** @return list<string> */
    public function getAvailableNames(): array
    {
        return array_keys($this->locator->getProvidedServices());
    }
}
