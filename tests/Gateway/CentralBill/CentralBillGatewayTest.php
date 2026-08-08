<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Gateway\CentralBill;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\Enum\Currency;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillConfig;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(CentralBillGateway::class)]
final class CentralBillGatewayTest extends TestCase
{
    private CentralBillConfig $config;
    private CentralBillGateway $gateway;

    protected function setUp(): void
    {
        $this->config = new CentralBillConfig(
            applicationId: 'fbab3ccc-719e-11ed-93ad-02420a0003c1',
            applicationSecret: 'app!secret',
            webPayBaseUrl: 'https://webpay.example.com/webpay',
            defaultCallbackUrl: 'https://acme.net/payment/callback/centralbill',
            defaultRedirectUrl: 'https://acme.net/payment/return',
        );

        $this->gateway = new CentralBillGateway($this->config);
    }

    #[Test]
    public function it_returns_the_correct_name(): void
    {
        $this->assertSame('centralbill', $this->gateway->getName());
    }

    #[Test]
    public function it_is_configured_when_credentials_are_present(): void
    {
        $this->assertTrue($this->gateway->isConfigured());
    }

    #[Test]
    public function it_is_not_configured_when_application_id_is_empty(): void
    {
        $gateway = new CentralBillGateway(new CentralBillConfig('', 'secret'));
        $this->assertFalse($gateway->isConfigured());
    }

    #[Test]
    public function it_is_not_configured_when_secret_is_empty(): void
    {
        $gateway = new CentralBillGateway(new CentralBillConfig('id', ''));
        $this->assertFalse($gateway->isConfigured());
    }

    #[Test]
    public function it_throws_when_not_configured_on_initiate(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('misconfigured');

        $gateway = new CentralBillGateway(new CentralBillConfig('', ''));
        $gateway->initiate($this->makeRequest());
    }

    #[Test]
    public function it_returns_a_redirect_response_on_initiate(): void
    {
        $response = $this->gateway->initiate($this->makeRequest());

        $this->assertSame(PaymentStatus::INITIATED, $response->status);
        $this->assertTrue($response->requiresRedirect());
        $this->assertStringStartsWith('https://pay.sandbox.centralbill.app/?', $response->redirectUrl);
    }

    #[Test]
    public function it_includes_required_params_in_redirect_url(): void
    {
        $response = $this->gateway->initiate($this->makeRequest());

        $this->assertStringContainsString('applicationId=', $response->redirectUrl);
        $this->assertStringContainsString('signature=', $response->redirectUrl);
        $this->assertStringContainsString('ORDER-42', $response->redirectUrl);
        $this->assertStringContainsString('XOF', $response->redirectUrl);
    }

    #[Test]
    public function it_uses_production_url_when_sandbox_is_false(): void
    {
        $config = new CentralBillConfig('id', 'secret', webPayBaseUrl: 'https://pay.centralbill.app');
        $gateway = new CentralBillGateway($config);

        $response = $gateway->initiate($this->makeRequest());

        $this->assertStringStartsWith('https://pay.centralbill.app/?', $response->redirectUrl);
    }

    #[Test]
    public function it_returns_success_on_completed_ipn(): void
    {
        $payload = $this->makeCallbackPayload($this->makeIpnBody('COMPLETED'));
        $result = $this->gateway->handle($payload);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('1', $result->invoiceId);
        $this->assertSame('tx-123', $result->gatewayTransactionId);
        $this->assertSame(5000, $result->amount);
        $this->assertNull($result->errorMessage);
    }

    #[Test]
    public function it_returns_failed_on_refused_ipn(): void
    {
        $payload = $this->makeCallbackPayload($this->makeIpnBody('REFUSED'));
        $result = $this->gateway->handle($payload);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertNotNull($result->errorMessage);
    }

    #[Test]
    public function it_returns_cancelled_on_canceled_ipn(): void
    {
        $payload = $this->makeCallbackPayload($this->makeIpnBody('CANCELED'));
        $result = $this->gateway->handle($payload);

        $this->assertSame(PaymentStatus::CANCELLED, $result->status);
    }

    #[Test]
    public function it_throws_on_invalid_json_body(): void
    {
        $this->expectException(InvalidCallbackException::class);
        $this->expectExceptionMessage('malformed payload');

        $request = Request::create('/payment/callback/centralbill', 'POST', content: 'not-json');
        $this->gateway->handle(new CallbackPayload($request, 'centralbill'));
    }

    #[Test]
    public function it_throws_on_missing_transaction_id(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeIpnBody('COMPLETED');
        unset($body['id']);

        $this->gateway->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function it_throws_on_missing_result_status(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeIpnBody('COMPLETED');
        unset($body['result']['status']);

        $this->gateway->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function it_throws_on_missing_invoice_id(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeIpnBody('COMPLETED');
        unset($body['invoice']['id']);

        $this->gateway->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function it_returns_pending_for_get_transaction_status(): void
    {
        $this->assertSame(PaymentStatus::PENDING, $this->gateway->getTransactionStatus('tx-123'));
    }

    private function makeRequest(): PaymentRequest
    {
        return new PaymentRequest(
            amount: 5000,
            currency: Currency::XOF,
            invoiceId: 'ORDER-42',
            customerId: 'client@example.com',
            invoiceIssuedAt: new \DateTime(),
        );
    }

    private function makeIpnBody(string $status): array
    {
        return [
            'id' => 'tx-123',
            'result' => ['status' => $status],
            'invoice' => [
                'id' => '1',
                'customerId' => 'client@example.com',
                'totalAmount' => ['amount' => 5000, 'currency' => 'XOF'],
                'issuedAt' => '2022-12-21T20:11:49+00:00',
            ],
            'paymentFee' => ['amount' => 0, 'currency' => 'XOF'],
        ];
    }

    private function makeCallbackPayload(array $body): CallbackPayload
    {
        $request = Request::create(
            '/payment/callback/centralbill',
            'POST',
            content: json_encode($body),
        );
        $request->headers->set('Content-Type', 'application/json');

        return new CallbackPayload($request, 'centralbill');
    }
}
