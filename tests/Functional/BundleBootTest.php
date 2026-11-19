<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Functional;

use E2K\PaymentBundle\Gateway\CentralBill\CentralBillGateway;
use E2K\PaymentBundle\Gateway\Paystack\PaystackGateway;
use E2K\PaymentBundle\Registry\PaymentGatewayRegistry;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Boots a real kernel with FrameworkBundle to check the bundle integrates with a Symfony application.
 */
#[CoversNothing]
final class BundleBootTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir().'/e2k_payment_bundle_tests');
        parent::tearDownAfterClass();
    }

    #[Test]
    public function itBootsAndExposesTheGateways(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $resolver = $container->get(ActiveGatewayResolver::class);
        $this->assertInstanceOf(ActiveGatewayResolver::class, $resolver);
        $this->assertInstanceOf(CentralBillGateway::class, $resolver->resolve());
        $this->assertInstanceOf(PaystackGateway::class, $resolver->resolve('paystack'));

        $registry = $container->get(PaymentGatewayRegistry::class);
        $this->assertInstanceOf(PaymentGatewayRegistry::class, $registry);
        $this->assertEqualsCanonicalizing(['centralbill', 'paystack'], $registry->getAvailableNames());
    }

    #[Test]
    public function itShipsFrenchAndEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get('translator');
        $this->assertInstanceOf(TranslatorInterface::class, $translator);

        $this->assertSame(
            'Le service de paiement est temporairement indisponible.',
            $translator->trans('error.service_unavailable', [], 'E2KPaymentBundle', 'fr'),
        );
        $this->assertSame(
            'Payment service is currently unavailable.',
            $translator->trans('error.service_unavailable', [], 'E2KPaymentBundle', 'en'),
        );
    }

    #[Test]
    public function itConvertsAnUnknownGatewayIntoA404JsonResponseInProduction(): void
    {
        $kernel = self::bootKernel(['debug' => false]);

        $response = $kernel->handle(Request::create('/payments/callback/unknown', 'POST'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"error":{"code":"gateway.not_found","message":"Payment gateway not found."}}',
            (string) $response->getContent(),
        );
    }

    #[Test]
    public function itLetsKnownGatewaysThrough(): void
    {
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/payments/callback/paystack', 'POST'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }
}
