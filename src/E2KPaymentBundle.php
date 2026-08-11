<?php

declare(strict_types=1);

namespace E2K\PaymentBundle;

use E2K\PaymentBundle\EventListener\PaymentExceptionListener;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillConfig;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillGateway;
use E2K\PaymentBundle\Registry\PaymentGatewayRegistry;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
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
                    ->info('Prefix for payment-related routes.')
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
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

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
                    new Reference('logger'),
                    new Reference('kernel'),
                    new Reference('translator'),
                    $config['payment_route_prefix'],
                ])
                ->addTag('kernel.event_listener', [
                    'event' => 'kernel.exception',
                    'priority' => 10,
                ])
                ->setPublic(false),
        );

        if ($config['gateways']['centralbill']['enabled'] ?? false) {
            $this->loadCentralBill($config['gateways']['centralbill'], $container);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

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
                ])
                ->setPublic(false),
        );

        $container->setDefinition(
            'e2k_payment.gateway.centralbill',
            (new Definition(CentralBillGateway::class))
                ->setArgument(0, new Reference('e2k_payment.gateway.centralbill.config'))
                ->addTag('e2k_payment.gateway')
                ->setPublic(false),
        );
    }
}
