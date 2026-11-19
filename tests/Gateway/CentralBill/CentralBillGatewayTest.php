<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Gateway\CentralBill;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\DTO\PaymentResponse;
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
    public function itReturnsTheCorrectName(): void
    {
        $this->assertSame('centralbill', $this->gateway->getName());
    }

    #[Test]
    public function itIsConfiguredWhenCredentialsArePresent(): void
    {
        $this->assertTrue($this->gateway->isConfigured());
    }

    #[Test]
    public function itIsNotConfiguredWhenApplicationIdIsEmpty(): void
    {
        $gateway = new CentralBillGateway(new CentralBillConfig('', 'secret', 'https://pay.example.com/'));
        $this->assertFalse($gateway->isConfigured());
    }

    #[Test]
    public function itIsNotConfiguredWhenSecretIsEmpty(): void
    {
        $gateway = new CentralBillGateway(new CentralBillConfig('id', '', 'https://pay.example.com/'));
        $this->assertFalse($gateway->isConfigured());
    }

    #[Test]
    public function itThrowsWhenNotConfiguredOnInitiate(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('misconfigured');

        $gateway = new CentralBillGateway(new CentralBillConfig('', '', 'https://pay.example.com/'));
        $gateway->initiate($this->makeRequest());
    }

    #[Test]
    public function itReturnsARedirectResponseOnInitiate(): void
    {
        $response = $this->gateway->initiate($this->makeRequest());

        $this->assertSame(PaymentStatus::INITIATED, $response->status);
        $this->assertTrue($response->requiresRedirect());
        $this->assertStringStartsWith('https://webpay.example.com/webpay/?', $this->redirectUrl($response));
    }

    #[Test]
    public function itIncludesRequiredParamsInRedirectUrl(): void
    {
        $url = $this->redirectUrl($this->gateway->initiate($this->makeRequest()));

        $this->assertStringContainsString('applicationId=', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('ORDER-42', $url);
        $this->assertStringContainsString('XOF', $url);
    }

    #[Test]
    public function itUsesTheConfiguredWebPayBaseUrl(): void
    {
        $config = new CentralBillConfig('id', 'secret', webPayBaseUrl: 'https://pay.centralbill.app');
        $gateway = new CentralBillGateway($config);

        $response = $gateway->initiate($this->makeRequest());

        $this->assertStringStartsWith('https://pay.centralbill.app/?', $this->redirectUrl($response));
    }

    #[Test]
    public function itSendsTheInvoiceDatesOfThePaymentRequest(): void
    {
        $request = new PaymentRequest(
            amount: '5000',
            currency: Currency::XOF,
            invoiceId: 'ORDER-42',
            customerId: 'client@example.com',
            invoiceIssuedAt: new \DateTimeImmutable('2026-01-10T08:00:00+00:00'),
            invoicedDueDate: new \DateTimeImmutable('2026-01-12T08:00:00+00:00'),
            invoiceExpiresAt: new \DateTimeImmutable('2026-01-20T08:00:00+00:00'),
        );

        $params = $this->queryParams($this->gateway->initiate($request));

        $this->assertSame('2026-01-10T08:00:00+00:00', $params['invoice']['issuedAt']);
        $this->assertSame('2026-01-12T08:00:00+00:00', $params['invoice']['dueDate']);
        $this->assertSame('2026-01-20T08:00:00+00:00', $params['invoice']['expiresAt']);
    }

    #[Test]
    public function itDefaultsDueAndExpiryDatesWhenNotProvided(): void
    {
        $params = $this->queryParams($this->gateway->initiate($this->makeRequest()));

        $dueDate = new \DateTimeImmutable($params['invoice']['dueDate']);
        $expiresAt = new \DateTimeImmutable($params['invoice']['expiresAt']);

        $this->assertEqualsWithDelta((new \DateTimeImmutable('+7 days'))->getTimestamp(), $dueDate->getTimestamp(), 60);
        $this->assertEqualsWithDelta((new \DateTimeImmutable('+30 days'))->getTimestamp(), $expiresAt->getTimestamp(), 60);
    }

    #[Test]
    public function itReturnsSuccessOnCompletedIpn(): void
    {
        $payload = $this->makeCallbackPayload($this->makeIpnBody('COMPLETED'));
        $result = $this->gateway->handle($payload);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('1', $result->invoiceId);
        $this->assertSame('tx-123', $result->gatewayTransactionId);
        $this->assertSame(5000, (int) $result->amount);
        $this->assertNull($result->errorMessage);
    }

    #[Test]
    public function itReturnsFailedOnRefusedIpn(): void
    {
        $payload = $this->makeCallbackPayload($this->makeIpnBody('REFUSED'));
        $result = $this->gateway->handle($payload);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertNotNull($result->errorMessage);
    }

    #[Test]
    public function itReturnsCancelledOnCanceledIpn(): void
    {
        $payload = $this->makeCallbackPayload($this->makeIpnBody('CANCELED'));
        $result = $this->gateway->handle($payload);

        $this->assertSame(PaymentStatus::CANCELLED, $result->status);
    }

    #[Test]
    public function itThrowsOnInvalidJsonBody(): void
    {
        $this->expectException(InvalidCallbackException::class);
        $this->expectExceptionMessage('malformed payload');

        $request = Request::create('/payment/callback/centralbill', 'POST', content: 'not-json');
        $this->gateway->handle(new CallbackPayload($request, 'centralbill'));
    }

    #[Test]
    public function itThrowsOnMissingTransactionId(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeIpnBody('COMPLETED');
        unset($body['id']);

        $this->gateway->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function itThrowsOnMissingResultStatus(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeIpnBody('COMPLETED');
        unset($body['result']['status']);

        $this->gateway->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function itThrowsOnMissingInvoiceId(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $body = $this->makeIpnBody('COMPLETED');
        unset($body['invoice']['id']);

        $this->gateway->handle($this->makeCallbackPayload($body));
    }

    #[Test]
    public function itReturnsPendingForGetTransactionStatus(): void
    {
        $this->assertSame(PaymentStatus::PENDING, $this->gateway->getTransactionStatus('tx-123'));
    }

    private function makeRequest(): PaymentRequest
    {
        return new PaymentRequest(
            amount: '5000',
            currency: Currency::XOF,
            invoiceId: 'ORDER-42',
            customerId: 'client@example.com',
            invoiceIssuedAt: new \DateTime(),
            customerName: 'John Doe',
        );
    }

    private function redirectUrl(PaymentResponse $response): string
    {
        if (null === $response->redirectUrl) {
            $this->fail('A redirect URL was expected.');
        }

        return $response->redirectUrl;
    }

    /**
     * @return array{invoice: array{issuedAt: string, dueDate: string, expiresAt: string}}
     */
    private function queryParams(PaymentResponse $response): array
    {
        parse_str((string) parse_url($this->redirectUrl($response), \PHP_URL_QUERY), $parsed);

        /** @var array{invoice: array{issuedAt: string, dueDate: string, expiresAt: string}} $params */
        $params = $parsed;

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $body
     */
    private function makeCallbackPayload(array $body): CallbackPayload
    {
        $request = Request::create(
            '/payment/callback/centralbill',
            'POST',
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');

        return new CallbackPayload($request, 'centralbill');
    }
}
