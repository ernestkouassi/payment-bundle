<?php
declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Resolver;

use E2K\PaymentBundle\Exception\GatewayNotFoundException;
use E2K\PaymentBundle\PaymentGatewayInterface;
use E2K\PaymentBundle\Registry\PaymentGatewayRegistry;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveGatewayResolver::class)]
final class ActiveGatewayResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_the_default_gateway(): void
    {
        $gateway  = $this->makeConfiguredGateway('centralbill');
        $registry = $this->makeRegistry('centralbill', $gateway);
        $resolver = new ActiveGatewayResolver($registry, 'centralbill');

        $this->assertSame($gateway, $resolver->resolve());
    }

    #[Test]
    public function it_resolves_an_explicit_gateway_by_name(): void
    {
        $gateway  = $this->makeConfiguredGateway('wave');
        $registry = $this->makeRegistry('wave', $gateway);
        $resolver = new ActiveGatewayResolver($registry, 'centralbill');

        $this->assertSame($gateway, $resolver->resolve('wave'));
    }

    #[Test]
    public function it_throws_when_gateway_is_not_found(): void
    {
        $this->expectException(GatewayNotFoundException::class);

        $registry = $this->createMock(PaymentGatewayRegistry::class);
        $registry->method('get')->willThrowException(GatewayNotFoundException::forName('unknown'));

        $resolver = new ActiveGatewayResolver($registry, 'unknown');
        $resolver->resolve();
    }

    #[Test]
    public function it_throws_when_gateway_is_not_configured(): void
    {
        $this->expectException(GatewayNotFoundException::class);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('isConfigured')->willReturn(false);

        $registry = $this->makeRegistry('centralbill', $gateway);
        $resolver = new ActiveGatewayResolver($registry, 'centralbill');
        $resolver->resolve();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeConfiguredGateway(string $name): PaymentGatewayInterface
    {
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('getName')->willReturn($name);
        $gateway->method('isConfigured')->willReturn(true);

        return $gateway;
    }

    private function makeRegistry(string $name, PaymentGatewayInterface $gateway): PaymentGatewayRegistry
    {
        $registry = $this->createMock(PaymentGatewayRegistry::class);
        $registry->method('get')->with($name)->willReturn($gateway);

        return $registry;
    }
}
