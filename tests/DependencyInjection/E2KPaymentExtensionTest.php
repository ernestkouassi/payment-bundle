<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\DependencyInjection;

use E2K\PaymentBundle\E2KPaymentBundle;
use E2K\PaymentBundle\EventListener\PaymentExceptionListener;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillConfig;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillGateway;
use E2K\PaymentBundle\Gateway\Paystack\PaystackConfig;
use E2K\PaymentBundle\Gateway\Paystack\PaystackGateway;
use E2K\PaymentBundle\Registry\PaymentGatewayRegistry;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use E2K\PaymentBundle\Tests\Fixtures\CustomGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Compiles a bare container (no FrameworkBundle: no logger, no translator, no http_client)
 * to check the bundle extension wiring and its configuration tree.
 */
#[CoversClass(E2KPaymentBundle::class)]
final class E2KPaymentExtensionTest extends TestCase
{
    #[Test]
    public function itCompilesWithoutLoggerTranslatorNorHttpClient(): void
    {
        $container = $this->compile(['default_gateway' => 'centralbill']);

        $this->assertInstanceOf(ActiveGatewayResolver::class, $container->get(ActiveGatewayResolver::class));
        $this->assertInstanceOf(PaymentExceptionListener::class, $container->get('e2k_payment.exception_listener'));

        $registry = $container->get(PaymentGatewayRegistry::class);
        $this->assertInstanceOf(PaymentGatewayRegistry::class, $registry);
        $this->assertSame([], $registry->getAvailableNames());
    }

    #[Test]
    public function itRegistersTheExceptionListenerAsASubscriber(): void
    {
        $definition = $this->compile(['default_gateway' => 'centralbill'])->getDefinition('e2k_payment.exception_listener');

        $this->assertTrue($definition->hasTag('kernel.event_subscriber'));
        $this->assertFalse($definition->hasTag('kernel.event_listener'));
        $this->assertSame('/payments/', $definition->getArgument(3));
    }

    #[Test]
    public function itRegistersTheCentralBillGatewayWithItsDefaultTolerance(): void
    {
        $container = $this->compile([
            'default_gateway' => 'centralbill',
            'gateways' => ['centralbill' => [
                'enabled' => true,
                'application_id' => 'app',
                'application_secret' => 'secret',
                'web_pay_baseUrl' => 'https://pay.example.com',
            ]],
        ]);

        $this->assertInstanceOf(CentralBillGateway::class, $this->resolver($container)->resolve());

        $config = $container->get('e2k_payment.gateway.centralbill.config');
        $this->assertInstanceOf(CentralBillConfig::class, $config);
        $this->assertSame(300, $config->callbackTolerance);
    }

    #[Test]
    public function itRegistersThePaystackGatewayWithDefaults(): void
    {
        $container = $this->compile(
            ['default_gateway' => 'paystack', 'gateways' => ['paystack' => ['enabled' => true, 'secret_key' => 'sk_test']]],
            static fn (ContainerBuilder $c) => $c->register('http_client', MockHttpClient::class),
        );

        $this->assertInstanceOf(PaystackGateway::class, $this->resolver($container)->resolve());

        $config = $container->get('e2k_payment.gateway.paystack.config');
        $this->assertInstanceOf(PaystackConfig::class, $config);
        $this->assertSame('https://api.paystack.co', $config->apiBaseUrl);
        $this->assertSame(PaystackConfig::DEFAULT_TIMEOUT, $config->timeout);
        $this->assertNull($config->webhookSecret);
    }

    #[Test]
    public function itPassesThePaystackWebhookSecret(): void
    {
        $container = $this->compile(
            ['default_gateway' => 'paystack', 'gateways' => ['paystack' => ['enabled' => true, 'secret_key' => 'sk_test', 'webhook_secret' => 'whsec_abc']]],
            static fn (ContainerBuilder $c) => $c->register('http_client', MockHttpClient::class),
        );

        $config = $container->get('e2k_payment.gateway.paystack.config');
        $this->assertInstanceOf(PaystackConfig::class, $config);
        $this->assertSame('whsec_abc', $config->webhookSecret);
    }

    #[Test]
    public function itAutoconfiguresCustomGateways(): void
    {
        $container = $this->compile(
            ['default_gateway' => 'custom'],
            static fn (ContainerBuilder $c) => $c->register(CustomGateway::class)->setAutoconfigured(true),
        );

        $this->assertInstanceOf(CustomGateway::class, $this->resolver($container)->resolve());
    }

    #[Test]
    public function itRejectsAnEmptyRoutePrefix(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->compile(['default_gateway' => 'centralbill', 'payment_route_prefix' => '']);
    }

    #[Test]
    public function itRequiresADefaultGateway(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->compile([]);
    }

    /**
     * @param array<string, mixed>                     $config
     * @param (callable(ContainerBuilder): mixed)|null $configure
     */
    private function compile(array $config, ?callable $configure = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new E2KPaymentBundle();
        $extension = $bundle->getContainerExtension();
        $this->assertNotNull($extension);

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $bundle->build($container);

        if (null !== $configure) {
            $configure($container);
        }

        // Expose the bundle services so that the test can fetch them after compilation.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'e2k_payment.')) {
                        $definition->setPublic(true);
                    }
                }
                foreach ($container->getAliases() as $id => $alias) {
                    if (str_starts_with($id, 'E2K\\')) {
                        $alias->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);

        $container->compile();

        return $container;
    }

    private function resolver(ContainerBuilder $container): ActiveGatewayResolver
    {
        $resolver = $container->get(ActiveGatewayResolver::class);
        $this->assertInstanceOf(ActiveGatewayResolver::class, $resolver);

        return $resolver;
    }
}
