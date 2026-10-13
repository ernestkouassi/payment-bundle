<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Gateway\Paystack;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\Enum\Currency;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\Gateway\Paystack\PaystackConfig;
use E2K\PaymentBundle\Gateway\Paystack\PaystackGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(PaystackGateway::class)]
final class PaystackGatewayTest extends TestCase
{
    private const SECRET = 'sk_test_abc123';

    private PaystackConfig $config;
    private HttpClientInterface $httpClient;
    private PaystackGateway $gateway;

    protected function setUp(): void
    {
        $this->config = new PaystackConfig(
            secretKey: self::SECRET,
            apiBaseUrl: 'https://api.paystack.co',
            defaultCallbackUrl: 'https://acme.net/payment/callback/paystack',
        );

        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->gateway = new PaystackGateway($this->config, $this->httpClient);
    }

    #[Test]
    public function itReturnsCorrectName(): void
    {
        $this->assertSame('paystack', $this->gateway->getName());
    }

    #[Test]
    public function itIsConfiguredWithValidSecret(): void
    {
        $this->assertTrue($this->gateway->isConfigured());
    }

    #[Test]
    public function itIsNotConfiguredWhenSecretIsEmpty(): void
    {
        $gw = new PaystackGateway(new PaystackConfig(''), $this->httpClient);
        $this->assertFalse($gw->isConfigured());
    }

    #[Test]
    public function itThrowsWhenNotConfiguredOnInitiate(): void
    {
        $this->expectException(GatewayException::class);
        $gw = new PaystackGateway(new PaystackConfig(''), $this->httpClient);
        $gw->initiate($this->makeRequest());
    }

    #[Test]
    public function itReturnsRedirectResponseWithAuthorizationUrl(): void
    {
        $this->httpClient
            ->method('request')
            ->willReturn($this->makeHttpResponse(200, [
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'abc123',
                    'reference' => 'ORDER-42',
                ],
            ]));

        $response = $this->gateway->initiate($this->makeRequest());

        $this->assertSame(PaymentStatus::INITIATED, $response->status);
        $this->assertTrue($response->requiresRedirect());
        $this->assertSame('https://checkout.paystack.com/abc123', $response->redirectUrl);
        $this->assertSame('abc123', $response->gatewayToken);
        $this->assertSame('ORDER-42', $response->gatewayTransactionId);
    }

    #[Test]
    public function itThrowsOnHttpErrorFromApi(): void
    {
        $this->expectException(GatewayException::class);

        $this->httpClient
            ->method('request')
            ->willReturn($this->makeHttpResponse(401, [
                'status' => false,
                'message' => 'Invalid key',
            ]));

        $this->gateway->initiate($this->makeRequest());
    }

    #[Test]
    public function itThrowsWhenAuthorizationUrlIsMissing(): void
    {
        $this->expectException(GatewayException::class);

        $this->httpClient
            ->method('request')
            ->willReturn($this->makeHttpResponse(200, [
                'status' => true,
                'data' => [],
            ]));

        $this->gateway->initiate($this->makeRequest());
    }

    #[Test]
    public function itReturnsSuccessOnChargeSuccessEvent(): void
    {
        $result = $this->gateway->handle(
            $this->makeCallbackPayload($this->makeWebhookBody('charge.success', 'success'))
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('ORDER-42', $result->reference);
        $this->assertSame('123456', $result->gatewayTransactionId);
        $this->assertSame(5000, $result->amount);
        $this->assertNull($result->errorMessage);
    }

    #[Test]
    public function itReturnsFailedOnNonChargeSuccessEvent(): void
    {
        $result = $this->gateway->handle(
            $this->makeCallbackPayload($this->makeWebhookBody('charge.failed', 'failed'))
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertNotNull($result->errorMessage);
    }

    #[Test]
    public function itReturnsCancelledOnAbandonedStatus(): void
    {
        $result = $this->gateway->handle(
            $this->makeCallbackPayload($this->makeWebhookBody('charge.success', 'abandoned'))
        );

        $this->assertSame(PaymentStatus::CANCELLED, $result->status);
    }

    #[Test]
    public function itThrowsOnInvalidJsonBody(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $request = Request::create('/payment/callback/paystack', 'POST', content: 'not-json');
        $this->gateway->handle(new CallbackPayload($request, 'paystack'));
    }

    #[Test]
    public function itThrowsOnMissingEventField(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeWebhookBody('charge.success', 'success');
        unset($body['event']);
        $this->gateway->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function itThrowsOnMissingReferenceField(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeWebhookBody('charge.success', 'success');
        unset($body['data']['reference']);
        $this->gateway->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function itReturnsSuccessStatusFromVerifyEndpoint(): void
    {
        $this->httpClient
            ->method('request')
            ->willReturn($this->makeHttpResponse(200, [
                'status' => true,
                'data' => ['status' => 'success'],
            ]));

        $this->assertSame(PaymentStatus::SUCCESS, $this->gateway->getTransactionStatus('ORDER-42'));
    }

    #[Test]
    public function itReturnsFailedWhenApiReturnsFalseStatus(): void
    {
        $this->httpClient
            ->method('request')
            ->willReturn($this->makeHttpResponse(200, ['status' => false]));

        $this->assertSame(PaymentStatus::FAILED, $this->gateway->getTransactionStatus('ORDER-42'));
    }

    private function makeRequest(): PaymentRequest
    {
        return new PaymentRequest(
            amount: 5000,
            currency: Currency::NGN,
            reference: 'ORDER-42',
            customerEmail: 'client@example.com',
        );
    }

    private function makeWebhookBody(string $event, string $status): array
    {
        return [
            'event' => $event,
            'data' => [
                'id' => 123456,
                'reference' => 'ORDER-42',
                'status' => $status,
                'amount' => 5000,
                'currency' => 'NGN',
                'customer' => ['email' => 'client@example.com'],
                'paid_at' => '2024-01-01T00:00:00.000Z',
            ],
        ];
    }

    private function makeCallbackPayload(array $body): CallbackPayload
    {
        $raw = json_encode($body);
        $request = Request::create('/payment/callback/paystack', 'POST', content: $raw);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('x-paystack-signature', hash_hmac('sha512', $raw, self::SECRET));

        return new CallbackPayload($request, 'paystack');
    }

    private function makeHttpResponse(int $status, array $data): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('toArray')->willReturn($data);

        return $response;
    }
}
