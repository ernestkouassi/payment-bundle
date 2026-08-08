<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Registry;

use E2K\PaymentBundle\Exception\GatewayNotFoundException;
use E2K\PaymentBundle\PaymentGatewayInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class PaymentGatewayRegistry
{
    public function __construct(
        /**
         * PSR-11 ServiceLocator injected by Symfony via #[AutowireLocator].
         * Indexed by the value returned by getName() for each gateway.
         * Lazy: a gateway is only instantiated on the first call to get().
         */
        #[AutowireLocator(
            services: 'e2k_payment.gateway',
            defaultIndexMethod: 'getName',
        )]
        private ServiceProviderInterface $locator,
    ) {}

    public function get(string $name): PaymentGatewayInterface
    {
        if (!$this->locator->has($name)) {
            throw GatewayNotFoundException::forName($name, $this->getAvailableNames());
        }

        /** @var PaymentGatewayInterface */
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
