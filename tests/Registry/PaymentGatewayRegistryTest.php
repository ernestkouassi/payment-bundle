<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Registry;

use E2K\PaymentBundle\Exception\GatewayNotFoundException;
use E2K\PaymentBundle\PaymentGatewayInterface;
use E2K\PaymentBundle\Registry\PaymentGatewayRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(PaymentGatewayRegistry::class)]
final class PaymentGatewayRegistryTest extends TestCase
{
    #[Test]
    public function itReturnsARegisteredGatewayAndListsAvailableNames(): void
    {
        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $registry = $this->makeRegistry(['centralbill' => $gateway, 'paystack' => $gateway]);

        $this->assertSame($gateway, $registry->get('centralbill'));
        $this->assertTrue($registry->has('paystack'));
        $this->assertFalse($registry->has('wave'));
        $this->assertSame(['centralbill', 'paystack'], $registry->getAvailableNames());
    }

    #[Test]
    public function itInstantiatesGatewaysLazily(): void
    {
        /** @var \ArrayObject<int, true> $factoryCalls */
        $factoryCalls = new \ArrayObject();
        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $registry = new PaymentGatewayRegistry(new ServiceLocator([
            'centralbill' => static function () use ($factoryCalls, $gateway) {
                $factoryCalls->append(true);

                return $gateway;
            },
        ]));

        $this->assertTrue($registry->has('centralbill'));
        $this->assertSame(['centralbill'], $registry->getAvailableNames());
        $this->assertCount(0, $factoryCalls);

        $registry->get('centralbill');
        $this->assertCount(1, $factoryCalls);
    }

    #[Test]
    public function itThrowsWithTheAvailableNamesForAnUnknownGateway(): void
    {
        $registry = $this->makeRegistry(['centralbill' => $this->createStub(PaymentGatewayInterface::class)]);

        try {
            $registry->get('wave');
            $this->fail('Expected GatewayNotFoundException was not thrown.');
        } catch (GatewayNotFoundException $e) {
            $this->assertSame('wave', $e->getRequestedName());
            $this->assertSame(['centralbill'], $e->getAvailableGateways());
            $this->assertStringContainsString('Available: [centralbill]', $e->getMessage());
        }
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
