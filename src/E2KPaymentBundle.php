<?php

declare(strict_types=1);

namespace E2K\PaymentBundle;

use E2K\PaymentBundle\EventListener\PaymentExceptionListener;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillConfig;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillGateway;
use E2K\PaymentBundle\Gateway\Paystack\PaystackConfig;
use E2K\PaymentBundle\Gateway\Paystack\PaystackGateway;
use E2K\PaymentBundle\Registry\PaymentGatewayRegistry;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final class E2KPaymentBundle extends AbstractBundle
{
    protected string $extensionAlias = 'e2k_payment';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_gateway')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Name of the default active gateway (e.g., centralbill).')
                ->end()
                ->scalarNode('payment_route_prefix')
                    ->defaultValue('/payments/')
                    ->cannotBeEmpty()
                    ->info('Prefix for payment-related routes (e.g., /payments/). Exceptions are only converted to JSON under this prefix.')
                ->end()
                ->arrayNode('gateways')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('centralbill')->canBeEnabled()
                            ->children()
                                ->scalarNode('application_id')->isRequired()->cannotBeEmpty()
                                ->end()
                                ->scalarNode('application_secret')->isRequired()->cannotBeEmpty()
                                ->end()
                                ->scalarNode('web_pay_baseUrl')->isRequired()->cannotBeEmpty()
                                ->end()
                                ->scalarNode('callback_url')->defaultNull()->end()
                                ->scalarNode('redirect_url')->defaultNull()->end()
                                ->integerNode('callback_tolerance')
                                    ->defaultValue(300)
                                    ->min(0)
                                    ->info('Maximum age in seconds of a signed IPN "Date" header. 0 disables the check.')
                                ->end()
                                ->scalarNode('api_base_url')
                                    ->defaultNull()
                                    ->info('Host of the CentralBill API (e.g. https://api.centralbill.app). When set, payments are pre-created server side before the redirection and can be polled (requires symfony/http-client).')
                                ->end()
                                ->floatNode('initiate_timeout')
                                    ->defaultValue(6.0)
                                    ->min(0)
                                    ->info('Timeout in seconds of the payment pre-creation (the customer is waiting).')
                                ->end()
                                ->floatNode('status_timeout')
                                    ->defaultValue(15.0)
                                    ->min(0)
                                    ->info('Timeout in seconds of a payment lookup (polling).')
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('paystack')->canBeEnabled()
                            ->children()
                                ->scalarNode('secret_key')->isRequired()->cannotBeEmpty()
                                ->end()
                                ->scalarNode('api_base_url')->defaultValue('https://api.paystack.co')->cannotBeEmpty()
                                ->end()
                                ->scalarNode('callback_url')->defaultNull()
                                ->end()
                                ->floatNode('timeout')
                                    ->defaultValue(10.0)
                                    ->min(0)
                                    ->info('Timeout in seconds of each HTTP call to the Paystack API.')
                                ->end()
                                ->scalarNode('webhook_secret')
                                    ->defaultNull()
                                    ->info('Webhook signing secret (whsec_...). When set, webhooks are verified with the "Paystack-Signature" header (timestamped, replay-protected) instead of the legacy "x-paystack-signature".')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $container
            ->registerForAutoconfiguration(PaymentGatewayInterface::class)
            ->addTag('e2k_payment.gateway');

        $container->setDefinition(
            'e2k_payment.registry',
            (new Definition(PaymentGatewayRegistry::class))
                ->setArgument(0, new ServiceLocatorArgument(
                    new TaggedIteratorArgument('e2k_payment.gateway', null, 'getName'),
                ))
                ->setPublic(false),
        );

        $container->setAlias(PaymentGatewayRegistry::class, 'e2k_payment.registry');

        $container->setDefinition(
            'e2k_payment.resolver',
            (new Definition(ActiveGatewayResolver::class))
                ->setArguments([
                    new Reference('e2k_payment.registry'),
                    $config['default_gateway'],
                ])
                ->setPublic(true),
        );

        $container
            ->setAlias(ActiveGatewayResolver::class, 'e2k_payment.resolver')
            ->setPublic(true);

        $container->setDefinition(
            'e2k_payment.exception_listener',
            (new Definition(PaymentExceptionListener::class))
                ->setArguments([
                    new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '%kernel.debug%',
                    new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    $config['payment_route_prefix'],
                ])
                ->addTag('kernel.event_subscriber')
                ->setPublic(false),
        );

        if ($config['gateways']['centralbill']['enabled'] ?? false) {
            $this->loadCentralBill($config['gateways']['centralbill'], $container);
        }

        if ($config['gateways']['paystack']['enabled'] ?? false) {
            $this->loadPaystack($config['gateways']['paystack'], $container);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadCentralBill(array $config, ContainerBuilder $container): void
    {
        $container->setDefinition(
            'e2k_payment.gateway.centralbill.config',
            (new Definition(CentralBillConfig::class))
                ->setArguments([
                    $config['application_id'],
                    $config['application_secret'],
                    $config['web_pay_baseUrl'],
                    $config['callback_url'],
                    $config['redirect_url'],
                    $config['callback_tolerance'],
                    $config['api_base_url'],
                    $config['initiate_timeout'],
                    $config['status_timeout'],
                ])
                ->setPublic(false),
        );

        $gateway = (new Definition(CentralBillGateway::class))
            ->setArgument(0, new Reference('e2k_payment.gateway.centralbill.config'))
            ->addTag('e2k_payment.gateway')
            ->setPublic(false);

        // The HTTP client is only needed by the optional server-side API (pre-creation and polling).
        if (null !== $config['api_base_url'] && '' !== $config['api_base_url']) {
            if (!class_exists(HttpClient::class)) {
                throw new \LogicException('The CentralBill server-side API (api_base_url) requires the Symfony HttpClient component. Try running "composer require symfony/http-client".');
            }

            $gateway
                ->setArgument(1, new Reference('http_client'))
                ->setArgument(2, new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }

        $container->setDefinition('e2k_payment.gateway.centralbill', $gateway);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadPaystack(array $config, ContainerBuilder $container): void
    {
        if (!class_exists(HttpClient::class)) {
            throw new \LogicException('The Paystack gateway requires the Symfony HttpClient component. Try running "composer require symfony/http-client".');
        }

        $container->setDefinition(
            'e2k_payment.gateway.paystack.config',
            (new Definition(PaystackConfig::class))
                ->setArguments([
                    $config['secret_key'],
                    $config['api_base_url'],
                    $config['callback_url'],
                    $config['timeout'],
                    $config['webhook_secret'],
                ])
                ->setPublic(false),
        );

        $container->setDefinition(
            'e2k_payment.gateway.paystack',
            (new Definition(PaystackGateway::class))
                ->setArguments([
                    new Reference('e2k_payment.gateway.paystack.config'),
                    new Reference('http_client'),
                ])
                ->addTag('e2k_payment.gateway')
                ->setPublic(false),
        );
    }
}
