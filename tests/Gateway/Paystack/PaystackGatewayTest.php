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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(PaystackGateway::class)]
#[CoversClass(PaystackConfig::class)]
final class PaystackGatewayTest extends TestCase
{
    private const SECRET = 'sk_test_abc123';

    private PaystackConfig $config;

    protected function setUp(): void
    {
        $this->config = new PaystackConfig(
            secretKey: self::SECRET,
            apiBaseUrl: 'https://api.paystack.co',
            defaultCallbackUrl: 'https://acme.net/payment/callback/paystack',
        );
    }

    #[Test]
    public function itReturnsCorrectName(): void
    {
        $this->assertSame('paystack', PaystackGateway::getName());
    }

    #[Test]
    public function itIsConfiguredWithValidSecret(): void
    {
        $this->assertTrue($this->makeGateway()->isConfigured());
    }

    #[Test]
    public function itIsNotConfiguredWhenSecretIsEmpty(): void
    {
        $gw = new PaystackGateway(new PaystackConfig(''), new MockHttpClient());
        $this->assertFalse($gw->isConfigured());
    }

    #[Test]
    public function itThrowsWhenNotConfiguredOnInitiate(): void
    {
        $this->expectException(GatewayException::class);
        $gw = new PaystackGateway(new PaystackConfig(''), new MockHttpClient());
        $gw->initiate($this->makeRequest());
    }

    #[Test]
    public function itDefaultsAndNormalizesTheApiBaseUrl(): void
    {
        $this->assertSame('https://api.paystack.co', (new PaystackConfig('sk'))->apiBaseUrl);
        $this->assertSame('https://api.example.com', (new PaystackConfig('sk', 'https://api.example.com/'))->apiBaseUrl);
    }

    #[Test]
    public function itReturnsRedirectResponseWithAuthorizationUrl(): void
    {
        $response = $this->makeGateway(self::json(200, [
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'abc123',
                'reference' => 'ORDER-42',
            ],
        ]))->initiate($this->makeRequest());

        $this->assertSame(PaymentStatus::INITIATED, $response->status);
        $this->assertTrue($response->requiresRedirect());
        $this->assertSame('https://checkout.paystack.com/abc123', $response->redirectUrl);
        $this->assertSame('abc123', $response->gatewayToken);
        $this->assertSame('ORDER-42', $response->gatewayTransactionId);
    }

    #[Test]
    public function itSendsAnAuthenticatedRequestWithTimeoutAndAmountInSubunits(): void
    {
        $mock = self::json(200, ['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']]);
        $gateway = new PaystackGateway(
            new PaystackConfig(self::SECRET, 'https://api.paystack.co/', timeout: 3.5),
            new MockHttpClient($mock),
        );

        $gateway->initiate($this->makeRequest());

        $this->assertSame('POST', $mock->getRequestMethod());
        $this->assertSame('https://api.paystack.co/transaction/initialize', $mock->getRequestUrl());
        $this->assertContains('Authorization: Bearer '.self::SECRET, $mock->getRequestOptions()['headers']);
        $this->assertSame(3.5, $mock->getRequestOptions()['timeout']);

        $sent = json_decode($mock->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('500000', $sent['amount']);
        $this->assertSame(['name' => 'John Doe'], $sent['metadata']);
    }

    #[Test]
    public function itThrowsOnHttpErrorFromApi(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('HTTP error 401');

        $this->makeGateway(self::json(401, ['status' => false, 'message' => 'Invalid key']))
            ->initiate($this->makeRequest());
    }

    #[Test]
    public function itThrowsAGatewayExceptionOnNonJsonResponseOnInitiate(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Unexpected response');

        $this->makeGateway(new MockResponse('<html>502 Bad Gateway</html>', ['http_code' => 502]))
            ->initiate($this->makeRequest());
    }

    #[Test]
    public function itThrowsAGatewayExceptionOnNetworkFailure(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Network error');

        $gateway = new PaystackGateway($this->config, new MockHttpClient(static function (): never {
            throw new TransportException('Connection timed out');
        }));

        $gateway->initiate($this->makeRequest());
    }

    #[Test]
    public function itThrowsWhenAuthorizationUrlIsMissing(): void
    {
        $this->expectException(GatewayException::class);

        $this->makeGateway(self::json(200, ['status' => true, 'data' => []]))
            ->initiate($this->makeRequest());
    }

    #[Test]
    public function itReturnsSuccessOnChargeSuccessEvent(): void
    {
        $result = $this->makeGateway()->handle(
            $this->makeCallbackPayload($this->makeWebhookBody('charge.success', 'success')),
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('ORDER-42', $result->invoiceId);
        $this->assertSame('123456', $result->gatewayTransactionId);
        $this->assertSame(5000, (int) $result->amount);
        $this->assertNull($result->errorMessage);
    }

    #[Test]
    public function itReturnsFailedOnNonChargeSuccessEvent(): void
    {
        $result = $this->makeGateway()->handle(
            $this->makeCallbackPayload($this->makeWebhookBody('charge.failed', 'failed')),
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertNotNull($result->errorMessage);
    }

    #[Test]
    public function itReturnsCancelledOnAbandonedStatus(): void
    {
        $result = $this->makeGateway()->handle(
            $this->makeCallbackPayload($this->makeWebhookBody('charge.success', 'abandoned')),
        );

        $this->assertSame(PaymentStatus::CANCELLED, $result->status);
    }

    #[Test]
    public function itThrowsOnInvalidJsonBody(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $request = Request::create('/payment/callback/paystack', 'POST', content: 'not-json');
        $this->makeGateway()->handle(new CallbackPayload($request, 'paystack'));
    }

    #[Test]
    public function itThrowsOnMissingEventField(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeWebhookBody('charge.success', 'success');
        unset($body['event']);
        $this->makeGateway()->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function itThrowsOnMissingReferenceField(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeWebhookBody('charge.success', 'success');
        unset($body['data']['reference']);
        $this->makeGateway()->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function itReturnsSuccessStatusFromVerifyEndpoint(): void
    {
        $mock = self::json(200, ['status' => true, 'data' => ['status' => 'success']]);

        $this->assertSame(PaymentStatus::SUCCESS, $this->makeGateway($mock)->getTransactionStatus('ORDER/42'));
        $this->assertSame('GET', $mock->getRequestMethod());
        $this->assertSame('https://api.paystack.co/transaction/verify/ORDER%2F42', $mock->getRequestUrl());
    }

    /**
     * A technical failure must never be reported as a FAILED payment.
     */
    #[Test]
    #[DataProvider('verifyFailures')]
    public function itThrowsInsteadOfReturningFailedWhenVerifyCannotConclude(MockResponse $response, string $expectedMessage): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->makeGateway($response)->getTransactionStatus('ORDER-42');
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function verifyFailures(): iterable
    {
        yield 'invalid key' => [self::json(401, ['status' => false, 'message' => 'Invalid key']), 'HTTP error 401'];
        yield 'unknown reference' => [self::json(404, ['status' => false, 'message' => 'Transaction reference not found']), 'HTTP error 404'];
        yield 'status false with 200' => [self::json(200, ['status' => false]), 'HTTP error 200'];
        yield 'server error' => [self::json(500, ['status' => false]), 'HTTP error 500'];
        yield 'html error page' => [new MockResponse('<html>502</html>', ['http_code' => 502]), 'Unexpected response'];
        yield 'missing data.status' => [self::json(200, ['status' => true, 'data' => []]), 'Missing data.status'];
    }

    #[Test]
    public function itThrowsWhenNotConfiguredOnGetTransactionStatus(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('misconfigured');

        (new PaystackGateway(new PaystackConfig(''), new MockHttpClient()))->getTransactionStatus('ORDER-42');
    }

    private function makeGateway(?MockResponse $response = null): PaystackGateway
    {
        return new PaystackGateway($this->config, new MockHttpClient($response ?? []));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(int $status, array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function makeRequest(): PaymentRequest
    {
        return new PaymentRequest(
            amount: '5000',
            currency: Currency::NGN,
            invoiceId: 'ORDER-42',
            customerId: 'client@example.com',
            invoiceIssuedAt: new \DateTime(),
            customerName: 'John Doe',
        );
    }

    /**
     * @return array{event: string, data: array<string, mixed>}
     */
    private function makeWebhookBody(string $event, string $status): array
    {
        return [
            'event' => $event,
            'data' => [
                'id' => 123456,
                'reference' => 'ORDER-42',
                'status' => $status,
                'amount' => 5000 * 100,
                'currency' => 'NGN',
                'customer' => ['email' => 'client@example.com'],
                'paid_at' => '2024-01-01T00:00:00.000Z',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makeCallbackPayload(array $body): CallbackPayload
    {
        $raw = json_encode($body, \JSON_THROW_ON_ERROR);
        $request = Request::create('/payment/callback/paystack', 'POST', content: $raw);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('x-paystack-signature', hash_hmac('sha512', $raw, self::SECRET));

        return new CallbackPayload($request, 'paystack');
    }
}
