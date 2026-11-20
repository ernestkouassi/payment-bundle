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
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(ActiveGatewayResolver::class)]
final class ActiveGatewayResolverTest extends TestCase
{
    #[Test]
    public function itResolvesTheDefaultGateway(): void
    {
        $gateway = $this->makeGateway(configured: true);
        $resolver = new ActiveGatewayResolver($this->makeRegistry(['centralbill' => $gateway]), 'centralbill');

        $this->assertSame($gateway, $resolver->resolve());
    }

    #[Test]
    public function itResolvesAnExplicitGatewayByName(): void
    {
        $gateway = $this->makeGateway(configured: true);
        $registry = $this->makeRegistry([
            'centralbill' => $this->makeGateway(configured: true),
            'wave' => $gateway,
        ]);

        $this->assertSame($gateway, (new ActiveGatewayResolver($registry, 'centralbill'))->resolve('wave'));
    }

    #[Test]
    public function itReportsAnUnknownRequestedGatewayAsAClientError(): void
    {
        $resolver = new ActiveGatewayResolver($this->makeRegistry(['centralbill' => $this->makeGateway(configured: true)]), 'centralbill');

        try {
            $resolver->resolve('unknown');
            $this->fail('Expected GatewayNotFoundException was not thrown.');
        } catch (GatewayNotFoundException $e) {
            $this->assertFalse($e->isServerMisconfiguration());
            $this->assertSame(['centralbill'], $e->getAvailableGateways());
        }
    }

    #[Test]
    public function itReportsAMissingDefaultGatewayAsAServerMisconfiguration(): void
    {
        $resolver = new ActiveGatewayResolver($this->makeRegistry(['centralbill' => $this->makeGateway(configured: true)]), 'wave');

        try {
            $resolver->resolve();
            $this->fail('Expected GatewayNotFoundException was not thrown.');
        } catch (GatewayNotFoundException $e) {
            $this->assertTrue($e->isServerMisconfiguration());
            $this->assertFalse($e->isNotConfigured());
            $this->assertSame('wave', $e->getRequestedName());
            $this->assertSame(['centralbill'], $e->getAvailableGateways());
        }
    }

    #[Test]
    public function itThrowsWhenGatewayIsNotConfigured(): void
    {
        $resolver = new ActiveGatewayResolver($this->makeRegistry(['centralbill' => $this->makeGateway(configured: false)]), 'centralbill');

        try {
            $resolver->resolve();
            $this->fail('Expected GatewayNotFoundException was not thrown.');
        } catch (GatewayNotFoundException $e) {
            $this->assertTrue($e->isServerMisconfiguration());
            $this->assertTrue($e->isNotConfigured());
        }
    }

    private function makeGateway(bool $configured): PaymentGatewayInterface
    {
        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('isConfigured')->willReturn($configured);

        return $gateway;
    }

    /**
     * @param array<string, PaymentGatewayInterface> $gateways
     */
    private function makeRegistry(array $gateways): PaymentGatewayRegistry
    {
        return new PaymentGatewayRegistry(new ServiceLocator(
            array_map(static fn (PaymentGatewayInterface $g) => static fn () => $g, $gateways),
        ));
    }
}
