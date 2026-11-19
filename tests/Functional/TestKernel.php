<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Functional;

use E2K\PaymentBundle\E2KPaymentBundle;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal application booting the bundle with FrameworkBundle.
 *
 * @internal
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new E2KPaymentBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/e2k_payment_bundle_tests/'.md5(__DIR__).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/e2k_payment_bundle_tests/'.md5(__DIR__).'/log';
    }

    /**
     * Callback endpoint as documented in the README.
     */
    public function callback(string $gateway): Response
    {
        $resolver = $this->getContainer()->get(ActiveGatewayResolver::class);
        \assert($resolver instanceof ActiveGatewayResolver);
        $resolver->resolve($gateway);

        return new Response('ok');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_client' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'default_locale' => 'en',
            'translator' => ['fallbacks' => ['en']],
            'router' => ['utf8' => true],
        ]);

        $container->extension('e2k_payment', [
            'default_gateway' => 'centralbill',
            'gateways' => [
                'centralbill' => [
                    'enabled' => true,
                    'application_id' => 'app',
                    'application_secret' => 'secret',
                    'web_pay_baseUrl' => 'https://pay.example.com',
                ],
                'paystack' => [
                    'enabled' => true,
                    'secret_key' => 'sk_test',
                ],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('payment_callback', '/payments/callback/{gateway}')->controller([$this, 'callback']);
    }
}
