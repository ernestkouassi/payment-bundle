<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Gateway\CentralBill;

use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\Enum\Currency;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillConfig;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillGateway;
use E2K\PaymentBundle\PaymentLookupInterface;
use E2K\PaymentBundle\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Server-side API of CentralBill: payment pre-creation before the redirection, and payment lookup (polling).
 */
#[CoversClass(CentralBillGateway::class)]
#[CoversClass(CentralBillConfig::class)]
final class CentralBillApiTest extends TestCase
{
    private const APP_ID = 'fbab3ccc-719e-11ed-93ad-02420a0003c1';

    private const SECRET = 'app!secret';

    private const API = 'https://api.centralbill.app/processor/v1/rest';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    // ---- Configuration ----

    #[Test]
    public function itAppendsTheRestPathToTheApiHostOnlyOnce(): void
    {
        $this->assertSame(self::API, $this->makeConfig('https://api.centralbill.app')->apiBaseUrl);
        $this->assertSame(self::API, $this->makeConfig('https://api.centralbill.app/')->apiBaseUrl);
        $this->assertSame(self::API, $this->makeConfig(self::API.'/')->apiBaseUrl);
    }

    #[Test]
    public function itDisablesTheApiWhenNoHostIsConfigured(): void
    {
        $this->assertFalse($this->makeConfig(null)->isApiEnabled());
        // An unset environment variable resolves to an empty string.
        $this->assertFalse($this->makeConfig('  ')->isApiEnabled());
        $this->assertTrue($this->makeConfig('https://api.centralbill.app')->isApiEnabled());
    }

    // ---- Pre-creation (initiate) ----

    #[Test]
    public function itPreCreatesThePaymentWithTheSignedPayloadAndUsesThePayLink(): void
    {
        $mock = self::json(201, [
            'id' => 'pay_123',
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.centralbill.app/processor/v1/rest/payments/pay_123'],
                ['rel' => 'pay', 'href' => 'https://pay.centralbill.app/p/pay_123'],
            ],
        ]);

        $response = $this->makeGateway($mock)->initiate($this->makeRequest());

        $this->assertSame(PaymentStatus::INITIATED, $response->status);
        $this->assertSame('pay_123', $response->gatewayTransactionId);
        $this->assertSame('https://pay.centralbill.app/p/pay_123', $response->redirectUrl);

        $this->assertSame('POST', $mock->getRequestMethod());
        $this->assertSame(self::API.'/payments', $mock->getRequestUrl());
        $this->assertSame(CentralBillConfig::DEFAULT_INITIATE_TIMEOUT, $mock->getRequestOptions()['timeout']);
        $this->assertNotContains('authorization', array_map(
            static fn (string $h) => strtolower(explode(':', $h, 2)[0]),
            $mock->getRequestOptions()['headers'],
        ));

        $sent = json_decode($mock->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(self::APP_ID, $sent['applicationId']);
        $this->assertSame(['id' => 'INV-42', 'customerId' => 'client@example.com', 'totalAmount' => ['amount' => '5000', 'currency' => 'XOF']], array_intersect_key($sent['invoice'], array_flip(['id', 'customerId', 'totalAmount'])));
        $this->assertSame('2026-01-10T08:00:00+00:00', $sent['invoice']['issuedAt']);
        $this->assertSame(
            hash('sha256', \sprintf('%s,INV-42,client@example.com,5000,XOF,%s', self::APP_ID, self::SECRET)),
            $sent['signature'],
        );
        $this->assertSame('https://acme.net/payments/callback/centralbill', $sent['callbackUrl']);
    }

    #[Test]
    public function itSendsTheSamePayloadToTheApiAndInTheWebPayLink(): void
    {
        // The hosted page finds the pre-created payment back through the identical invoice.id + signature couple.
        $mock = self::json(200, ['id' => 'pay_123']);

        $response = $this->makeGateway($mock)->initiate($this->makeRequest());

        parse_str((string) parse_url((string) $response->redirectUrl, \PHP_URL_QUERY), $query);
        $sent = json_decode($mock->getRequestOptions()['body'], true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame($sent, $query);
        $this->assertStringStartsWith('https://pay.centralbill.app/?', (string) $response->redirectUrl);
        $this->assertSame('pay_123', $response->gatewayTransactionId);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('creationEnvelopes')]
    public function itReadsThePaymentIdentifierFromTheKnownEnvelopes(array $body): void
    {
        $response = $this->makeGateway(self::json(200, $body))->initiate($this->makeRequest());

        $this->assertSame('pay_123', $response->gatewayTransactionId);
        $this->assertSame('https://pay.centralbill.app/p/pay_123', $response->redirectUrl);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function creationEnvelopes(): iterable
    {
        $links = [['rel' => 'pay', 'href' => 'https://pay.centralbill.app/p/pay_123']];

        yield 'flat' => [['id' => 'pay_123', 'links' => $links]];
        yield 'payment' => [['payment' => ['id' => 'pay_123', 'links' => $links]]];
        yield 'paymentId' => [['paymentId' => 'pay_123', 'links' => $links]];
        yield 'data' => [['data' => ['id' => 'pay_123', 'links' => $links]]];
    }

    #[Test]
    public function itIgnoresAPayLinkThatIsNotHttps(): void
    {
        $response = $this->makeGateway(self::json(200, [
            'id' => 'pay_123',
            'links' => [['rel' => 'pay', 'href' => 'javascript:alert(1)']],
        ]))->initiate($this->makeRequest());

        $this->assertStringStartsWith('https://pay.centralbill.app/?', (string) $response->redirectUrl);
    }

    /**
     * An unavailable API must never stop a customer from paying.
     */
    #[Test]
    #[DataProvider('creationFailures')]
    public function itFallsBackOnTheWebPayLinkWhenThePreCreationFails(MockResponse|\Closure $response): void
    {
        $gateway = new CentralBillGateway(
            $this->makeConfig('https://api.centralbill.app'),
            new MockHttpClient($response),
            $this->logger,
        );

        $result = $gateway->initiate($this->makeRequest());

        $this->assertSame(PaymentStatus::INITIATED, $result->status);
        $this->assertNull($result->gatewayTransactionId);
        $this->assertStringStartsWith('https://pay.centralbill.app/?', (string) $result->redirectUrl);
        $this->assertSame([LogLevel::WARNING], array_column($this->logger->records, 0));
    }

    /**
     * @return iterable<string, array{MockResponse|\Closure}>
     */
    public static function creationFailures(): iterable
    {
        yield 'server error' => [self::json(500, ['message' => 'boom'])];
        yield 'rate limited' => [self::json(429, ['message' => 'You reach the limit of 20 requests per 60 seconds'])];
        yield 'html page' => [new MockResponse('<html>502</html>', ['http_code' => 200])];
        yield 'no identifier' => [self::json(200, ['status' => 'ok'])];
        yield 'network' => [static function (): never {
            throw new TransportException('Connection timed out');
        }];
    }

    #[Test]
    public function itDoesNotCallTheApiWhenItIsDisabled(): void
    {
        $gateway = new CentralBillGateway(
            $this->makeConfig(null),
            new MockHttpClient(static function (): never {
                throw new \LogicException('The API must not be called.');
            }),
        );

        $response = $gateway->initiate($this->makeRequest());

        $this->assertNull($response->gatewayTransactionId);
        $this->assertStringStartsWith('https://pay.centralbill.app/?', (string) $response->redirectUrl);
    }

    // ---- Lookup (polling) ----

    #[Test]
    public function itReportsAPaymentWithACompletedTransactionAsSuccessful(): void
    {
        $mock = self::json(200, $this->paymentPayload([
            ['id' => 'tx_1', 'result' => ['status' => 'FAILED']],
            ['id' => 'tx_2', 'externalTransactionId' => 'OM-778', 'result' => ['status' => 'COMPLETED']],
        ]));
        $gateway = $this->makeGateway($mock);

        $this->assertInstanceOf(PaymentLookupInterface::class, $gateway);
        $result = $gateway->fetchPayment('pay/123');

        $this->assertSame('GET', $mock->getRequestMethod());
        $this->assertSame(self::API.'/payments/pay%2F123', $mock->getRequestUrl());
        $this->assertSame(CentralBillConfig::DEFAULT_STATUS_TIMEOUT, $mock->getRequestOptions()['timeout']);

        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('INV-42', $result->invoiceId);
        $this->assertSame('tx_2', $result->gatewayTransactionId);
        $this->assertSame('5000', $result->amount);
        $this->assertSame('client@example.com', $result->rawPayload['invoice']['customerId']);
    }

    #[Test]
    public function itReportsAPaymentWithoutCompletedTransactionAsPending(): void
    {
        // A failed attempt does not fail the payment: the customer can retry until it expires.
        $result = $this->makeGateway(self::json(200, $this->paymentPayload([
            ['id' => 'tx_1', 'result' => ['status' => 'FAILED']],
        ])))->fetchPayment('pay_123');

        $this->assertSame(PaymentStatus::PENDING, $result->status);
        $this->assertSame('pay_123', $result->gatewayTransactionId);
    }

    #[Test]
    public function itDoesNotTrustTheAmountAlreadyPaid(): void
    {
        $payload = $this->paymentPayload([]);
        $payload['totalAmountAlreadyPaid'] = ['amount' => 5000, 'currency' => 'XOF'];

        $this->assertSame(PaymentStatus::PENDING, $this->makeGateway(self::json(200, $payload))->fetchPayment('pay_123')->status);
    }

    #[Test]
    public function itAcceptsAFlatTransactionStatus(): void
    {
        $result = $this->makeGateway(self::json(200, $this->paymentPayload([
            ['id' => 'tx_1', 'status' => 'completed'],
        ])))->fetchPayment('pay_123');

        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('lookupEnvelopes')]
    public function itUnwrapsTheKnownPaymentEnvelopes(array $body): void
    {
        $result = $this->makeGateway(self::json(200, $body))->fetchPayment('pay_123');

        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('INV-42', $result->invoiceId);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function lookupEnvelopes(): iterable
    {
        $payment = [
            'id' => 'pay_123',
            'invoice' => ['id' => 'INV-42', 'totalAmount' => ['amount' => 5000, 'currency' => 'XOF']],
            'transactions' => [['id' => 'tx_1', 'result' => ['status' => 'COMPLETED']]],
        ];

        yield 'flat' => [$payment];
        yield 'payment' => [['payment' => $payment]];
        yield 'data' => [['data' => $payment]];
        yield 'data.payment' => [['data' => ['payment' => $payment]]];
    }

    #[Test]
    public function itLogsAPaymentAnsweredWithoutTransactionsList(): void
    {
        $payload = $this->paymentPayload([]);
        unset($payload['transactions']);

        $result = $this->makeGateway(self::json(200, $payload))->fetchPayment('pay_123');

        $this->assertSame(PaymentStatus::PENDING, $result->status);
        $this->assertSame([LogLevel::WARNING], array_column($this->logger->records, 0));
    }

    #[Test]
    public function itRejectsAPaymentWithoutInvoiceId(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Missing invoice.id');

        $this->makeGateway(self::json(200, ['id' => 'pay_123', 'transactions' => []]))->fetchPayment('pay_123');
    }

    /**
     * A technical failure must never be reported as a payment status.
     */
    #[Test]
    #[DataProvider('lookupFailures')]
    public function itThrowsWhenThePaymentCannotBeRead(MockResponse|\Closure $response, string $message, bool $rateLimited): void
    {
        try {
            $this->makeGateway($response)->fetchPayment('pay_123');
            $this->fail('Expected GatewayException was not thrown.');
        } catch (GatewayException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame($rateLimited, $e->isRateLimited());
        }
    }

    /**
     * @return iterable<string, array{MockResponse|\Closure, string, bool}>
     */
    public static function lookupFailures(): iterable
    {
        yield 'unknown payment' => [self::json(404, ['message' => 'Not found']), 'HTTP error 404', false];
        yield 'unauthorized' => [self::json(401, ['message' => 'Unauthorized']), 'HTTP error 401', false];
        yield 'rate limited' => [self::json(429, ['message' => 'You reach the limit of 20 requests per 60 seconds']), 'HTTP error 429', true];
        yield 'server error' => [self::json(503, []), 'HTTP error 503', false];
        yield 'html page' => [new MockResponse('<html>502</html>', ['http_code' => 200]), 'Non-JSON', false];
        yield 'network' => [static function (): never {
            throw new TransportException('Connection timed out');
        }, 'Network error', false];
    }

    #[Test]
    public function itRequiresTheApiToFetchAPayment(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('api_base_url');

        (new CentralBillGateway($this->makeConfig(null)))->fetchPayment('pay_123');
    }

    #[Test]
    public function itDelegatesTheTransactionStatusToTheLookup(): void
    {
        $gateway = $this->makeGateway(self::json(200, $this->paymentPayload([
            ['id' => 'tx_1', 'result' => ['status' => 'COMPLETED']],
        ])));

        $this->assertSame(PaymentStatus::SUCCESS, $gateway->getTransactionStatus('pay_123'));
    }

    #[Test]
    public function itKeepsReturningPendingWithoutTheApi(): void
    {
        $this->assertSame(PaymentStatus::PENDING, (new CentralBillGateway($this->makeConfig(null)))->getTransactionStatus('pay_123'));
    }

    private function makeGateway(MockResponse|\Closure $response): CentralBillGateway
    {
        return new CentralBillGateway(
            $this->makeConfig('https://api.centralbill.app'),
            new MockHttpClient($response),
            $this->logger,
        );
    }

    private function makeConfig(?string $apiBaseUrl): CentralBillConfig
    {
        return new CentralBillConfig(
            applicationId: self::APP_ID,
            applicationSecret: self::SECRET,
            webPayBaseUrl: 'https://pay.centralbill.app',
            defaultCallbackUrl: 'https://acme.net/payments/callback/centralbill',
            apiBaseUrl: $apiBaseUrl,
        );
    }

    private function makeRequest(): PaymentRequest
    {
        return new PaymentRequest(
            amount: '5000',
            currency: Currency::XOF,
            invoiceId: 'INV-42',
            customerId: 'client@example.com',
            invoiceIssuedAt: new \DateTimeImmutable('2026-01-10T08:00:00+00:00'),
        );
    }

    /**
     * @param list<array<string, mixed>> $transactions
     *
     * @return array<string, mixed>
     */
    private function paymentPayload(array $transactions): array
    {
        return [
            'id' => 'pay_123',
            'invoice' => [
                'id' => 'INV-42',
                'customerId' => 'client@example.com',
                'totalAmount' => ['amount' => 5000, 'currency' => 'XOF'],
            ],
            'totalAmountAlreadyPaid' => ['amount' => 0, 'currency' => 'XOF'],
            'transactions' => $transactions,
        ];
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
}
