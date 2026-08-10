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

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(ActiveGatewayResolver::class)]
final class ActiveGatewayResolverTest extends TestCase
{
    #[Test]
    public function itResolvesTheDefaultGateway(): void
    {
        $gateway = $this->makeConfiguredGateway('centralbill');
        $registry = $this->makeRegistry('centralbill', $gateway);
        $resolver = new ActiveGatewayResolver($registry, 'centralbill');

        $this->assertSame($gateway, $resolver->resolve());
    }

    #[Test]
    public function itResolvesAnExplicitGatewayByName(): void
    {
        $gateway = $this->makeConfiguredGateway('wave');
        $registry = $this->makeRegistry('wave', $gateway);
        $resolver = new ActiveGatewayResolver($registry, 'centralbill');

        $this->assertSame($gateway, $resolver->resolve('wave'));
    }

    #[Test]
    public function itThrowsWhenGatewayIsNotFound(): void
    {
        $this->expectException(GatewayNotFoundException::class);

        $registry = $this->createMock(PaymentGatewayRegistry::class);
        $registry->method('get')->willThrowException(GatewayNotFoundException::forName('unknown'));

        $resolver = new ActiveGatewayResolver($registry, 'unknown');
        $resolver->resolve();
    }

    #[Test]
    public function itThrowsWhenGatewayIsNotConfigured(): void
    {
        $this->expectException(GatewayNotFoundException::class);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('isConfigured')->willReturn(false);

        $registry = $this->makeRegistry('centralbill', $gateway);
        $resolver = new ActiveGatewayResolver($registry, 'centralbill');
        $resolver->resolve();
    }

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
