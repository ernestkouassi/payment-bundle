<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\CentralBill;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\CallbackResult;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\DTO\PaymentResponse;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\PaymentGatewayInterface;
use E2K\PaymentBundle\PaymentLookupInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CentralBill gateway.
 *
 * Without "api_base_url", payments only go through the signed Web Pay link and the IPN, as documented by CentralBill.
 * With it, initiate() also pre-creates the payment server side (POST {api}/payments) before the redirection, which
 * gives back a payment identifier usable for polling (fetchPayment() / getTransactionStatus()). The API is idempotent
 * on (applicationId, invoice.id, signature): the hosted page reached afterwards finds the same payment instead of
 * creating a second one. The IPN remains the primary source of truth; polling is a safety net when it never arrives.
 *
 * Automatically registered in the registry via: registerForAutoconfiguration(PaymentGatewayInterface::class)->addTag('e2k_payment.gateway')
 *
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class CentralBillGateway implements PaymentGatewayInterface, PaymentLookupInterface
{
    /** Transaction status meaning that the money has actually moved. */
    private const TRANSACTION_COMPLETED = 'COMPLETED';

    private CentralBillSignatureVerifier $signatureVerifier;

    private LoggerInterface $logger;

    /**
     * @param HttpClientInterface|null $httpClient Required when the server-side API is enabled (api_base_url)
     */
    public function __construct(
        private CentralBillConfig $config,
        private ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->signatureVerifier = new CentralBillSignatureVerifier(
            $this->config->applicationSecret,
            $this->config->callbackTolerance,
        );
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getName(): string
    {
        return 'centralbill';
    }

    public function isConfigured(): bool
    {
        return !empty($this->config->applicationId)
            && !empty($this->config->applicationSecret);
    }

    /**
     * Builds the signed Web Pay link and, when the server-side API is enabled, pre-creates the payment first.
     *
     * The pre-creation never blocks the payment: if the API is unreachable, rate limited or answers something
     * unusable, a warning is logged and the locally built link is returned with no gatewayTransactionId (polling is
     * then unavailable for this payment, the IPN still works). On success, gatewayTransactionId holds the CentralBill
     * payment identifier, and redirectUrl the payment link CentralBill returns for it ("links[rel=pay]") when present,
     * which guarantees that the customer settles the very payment that will be polled.
     *
     * @throws GatewayException If the gateway is not configured
     */
    public function initiate(PaymentRequest $request): PaymentResponse
    {
        if (!$this->isConfigured()) {
            throw GatewayException::configurationError(self::getName(), 'applicationId or applicationSecret is empty.');
        }

        $payload = $this->buildPaymentPayload($request);
        $localUrl = \sprintf('%s/?%s', rtrim($this->config->webPayBaseUrl, '/'), http_build_query($payload));

        $created = $this->preCreatePayment($payload);

        if (null === $created) {
            return new PaymentResponse(
                status: PaymentStatus::INITIATED,
                invoiceId: $request->invoiceId,
                redirectUrl: $localUrl,
            );
        }

        return new PaymentResponse(
            status: PaymentStatus::INITIATED,
            invoiceId: $request->invoiceId,
            gatewayTransactionId: $created['paymentId'],
            redirectUrl: $created['payLink'] ?? $localUrl,
            rawResponse: $created['response'],
        );
    }

    /**
     * Validates the HMAC-SHA256 signature of the CentralBill IPN.
     *
     * @throws InvalidCallbackException
     */
    public function validateSignature(CallbackPayload $payload): void
    {
        $request = $payload->httpRequest;

        $this->signatureVerifier->verify(
            headers: $payload->getHeaders(),
            method: strtolower($request->getMethod()),
            requestUri: $request->getRequestUri(),
            body: $payload->getBody(),
        );
    }

    /**
     * Processes the JSON body of an IPN and returns a normalized Callback Result.
     *
     * @throws InvalidCallbackException
     */
    public function handle(CallbackPayload $payload): CallbackResult
    {
        $body = json_decode($payload->getBody(), true);

        if (!\is_array($body)) {
            throw InvalidCallbackException::malformedPayload('Invalid JSON body.');
        }

        $transactionId = $body['id'] ?? null;
        $cbStatus = $body['result']['status'] ?? null;
        $invoiceId = $body['invoice']['id'] ?? null;
        $amount = $body['invoice']['totalAmount']['amount'] ?? null;

        if (!$transactionId) {
            throw InvalidCallbackException::missingField('id');
        }
        if (!$cbStatus) {
            throw InvalidCallbackException::missingField('result.status');
        }
        if (!$invoiceId) {
            throw InvalidCallbackException::missingField('invoice.id');
        }

        $status = CentralBillStatus::toPaymentStatus($cbStatus);

        $errorMessage = null;
        if (!$status->isSuccessful()) {
            $errorMessage = $body['result']['statusReason']
                ?? \sprintf('Transaction %s by CentralBill.', strtolower($cbStatus));
        }

        return new CallbackResult(
            status: $status,
            invoiceId: $invoiceId,
            gatewayTransactionId: $transactionId,
            amount: isset($amount) ? (string) $amount : null,
            errorMessage: $errorMessage,
            rawPayload: $body,
        );
    }

    /**
     * Current status of a payment pre-created by initiate().
     *
     * Without the server-side API (no api_base_url), CentralBill offers no way to query a payment and PENDING is
     * returned: rely on the IPN.
     *
     * @param string $gatewayTransactionId CentralBill payment identifier (PaymentResponse::$gatewayTransactionId)
     *
     * @throws GatewayException When the API is enabled and the payment cannot be read
     */
    public function getTransactionStatus(string $gatewayTransactionId): PaymentStatus
    {
        if (!$this->config->isApiEnabled()) {
            return PaymentStatus::PENDING;
        }

        return $this->fetchPayment($gatewayTransactionId)->status;
    }

    /**
     * Reads a payment back from CentralBill (GET {api}/payments/{id}).
     *
     * What settles a payment is the status of its transactions, not "totalAmountAlreadyPaid": the result is SUCCESS
     * as soon as one transaction is COMPLETED, PENDING otherwise. A failed attempt is not reported as FAILED, since
     * the customer can still retry on the same payment until it expires; failures are reported by the IPN.
     *
     * Before settling an order from this result, check that invoiceId, amount (invoice total) and
     * rawPayload['invoice']['customerId'] match it.
     *
     * @throws GatewayException If the API is not enabled, unreachable, rate limited (isRateLimited()), answers with
     *                          an HTTP error or with a payload that cannot be interpreted
     */
    public function fetchPayment(string $gatewayPaymentId): CallbackResult
    {
        $payment = $this->unwrapPayment(
            $this->callApi('GET', '/payments/'.rawurlencode($gatewayPaymentId), [], $this->config->statusTimeout),
        );

        $invoice = \is_array($payment['invoice'] ?? null) ? $payment['invoice'] : [];
        $invoiceId = $invoice['id'] ?? null;

        if (!\is_scalar($invoiceId) || '' === (string) $invoiceId) {
            throw GatewayException::unexpectedResponse(self::getName(), 'Missing invoice.id in payment response.', $payment);
        }

        if (!\is_array($payment['transactions'] ?? null)) {
            // A payload we do not know how to read, not a payment left unpaid: say so instead of polling it silently.
            $this->logger->warning('CentralBill payment {payment} answered without a "transactions" list.', [
                'payment' => $gatewayPaymentId,
                'keys' => array_keys($payment),
            ]);
        }

        $settled = $this->findSettledTransaction($payment);
        $amount = $invoice['totalAmount']['amount'] ?? null;

        return new CallbackResult(
            status: null !== $settled ? PaymentStatus::SUCCESS : PaymentStatus::PENDING,
            invoiceId: (string) $invoiceId,
            gatewayTransactionId: $this->scalarOrNull($settled['id'] ?? null) ?? $gatewayPaymentId,
            amount: is_numeric($amount) ? (string) $amount : null,
            rawPayload: $payment,
        );
    }

    /**
     * Payment payload shared by the Web Pay link (as query string) and the pre-creation API (as JSON).
     *
     * @return array<string, mixed>
     */
    private function buildPaymentPayload(PaymentRequest $request): array
    {
        $signature = $this->generatePaymentSignature(
            invoiceId: $request->invoiceId,
            customerId: $request->customerId,
            amount: $request->amount,
            currency: $request->currency->value,
        );

        return array_filter([
            'applicationId' => $this->config->applicationId,
            'invoice' => [
                'id' => $request->invoiceId,
                'customerId' => $request->customerId,
                'totalAmount' => [
                    'amount' => $request->amount,
                    'currency' => $request->currency->value,
                ],
                'issuedAt' => $request->invoiceIssuedAt->format(\DateTimeInterface::ATOM),
                'dueDate' => ($request->invoicedDueDate ?? new \DateTimeImmutable('+7 days'))->format(\DateTimeInterface::ATOM),
                'expiresAt' => ($request->invoiceExpiresAt ?? new \DateTimeImmutable('+30 days'))->format(\DateTimeInterface::ATOM),
            ],
            'description' => $request->description ?? $request->invoiceId,
            'signature' => $signature,
            'callbackUrl' => $request->callbackUrl ?? $this->config->defaultCallbackUrl,
            'redirectUrl' => $request->returnUrl ?? $this->config->defaultRedirectUrl,
        ], static fn ($v) => null !== $v && '' !== $v);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{paymentId: string, payLink: string|null, response: array<string, mixed>}|null Null when the
     *                                                                                             pre-creation is disabled or failed
     */
    private function preCreatePayment(array $payload): ?array
    {
        if (!$this->config->isApiEnabled()) {
            return null;
        }

        $invoiceId = $payload['invoice']['id'] ?? null;

        try {
            $response = $this->callApi('POST', '/payments', ['json' => $payload], $this->config->initiateTimeout);
        } catch (GatewayException $e) {
            $this->logger->warning('CentralBill payment pre-creation failed for invoice {invoice}, falling back on the Web Pay link (no polling for this payment): {message}', [
                'invoice' => $invoiceId,
                'message' => $e->getMessage(),
                'rate_limited' => $e->isRateLimited(),
            ]);

            return null;
        }

        $paymentId = $this->extractPaymentId($response);

        if (null === $paymentId) {
            $this->logger->warning('CentralBill returned no payment identifier for invoice {invoice}, polling unavailable for this payment.', [
                'invoice' => $invoiceId,
                'keys' => array_keys($response),
            ]);

            return null;
        }

        return ['paymentId' => $paymentId, 'payLink' => $this->extractPayLink($response), 'response' => $response];
    }

    /**
     * Calls the CentralBill REST API. No Authorization header is sent: the applicationId and the signature carried
     * by the payload authenticate the pre-creation.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    private function callApi(string $method, string $path, array $options, float $timeout): array
    {
        if (null === $this->config->apiBaseUrl || null === $this->httpClient) {
            throw GatewayException::configurationError(self::getName(), 'the server-side API is not enabled (api_base_url).');
        }

        $statusCode = 0;

        try {
            $response = $this->httpClient->request($method, $this->config->apiBaseUrl.$path, $options + [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $timeout,
                'max_duration' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                throw GatewayException::httpError(self::getName(), $statusCode, $response->getContent(false));
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw GatewayException::networkError(self::getName(), $e);
        } catch (DecodingExceptionInterface $e) {
            throw GatewayException::unexpectedResponse(self::getName(), \sprintf('Non-JSON response (HTTP %d).', $statusCode));
        } catch (HttpClientExceptionInterface $e) {
            throw GatewayException::networkError(self::getName(), $e);
        }

        return $data;
    }

    /**
     * The payment object itself, whichever envelope carries it ({...}, {payment: {...}}, {data: {...}}, {data: {payment: {...}}}).
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function unwrapPayment(array $response): array
    {
        $data = \is_array($response['data'] ?? null) ? $response['data'] : [];

        foreach ([$response['payment'] ?? null, $data['payment'] ?? null, $response['data'] ?? null] as $candidate) {
            if (\is_array($candidate) && (isset($candidate['invoice']) || isset($candidate['transactions']))) {
                /* @var array<string, mixed> $candidate */
                return $candidate;
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractPaymentId(array $response): ?string
    {
        $payment = \is_array($response['payment'] ?? null) ? $response['payment'] : [];
        $data = \is_array($response['data'] ?? null) ? $response['data'] : [];

        foreach ([$payment['id'] ?? null, $response['id'] ?? null, $response['paymentId'] ?? null, $data['id'] ?? null] as $candidate) {
            if (null !== $id = $this->scalarOrNull($candidate)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The hosted payment page CentralBill returns for the payment it has just created (links[rel="pay"].href).
     *
     * @param array<string, mixed> $response
     */
    private function extractPayLink(array $response): ?string
    {
        $payment = \is_array($response['payment'] ?? null) ? $response['payment'] : [];
        $data = \is_array($response['data'] ?? null) ? $response['data'] : [];

        foreach ([$response['links'] ?? null, $payment['links'] ?? null, $data['links'] ?? null] as $links) {
            if (!\is_array($links)) {
                continue;
            }

            foreach ($links as $link) {
                $href = \is_array($link) && 'pay' === ($link['rel'] ?? null) ? ($link['href'] ?? null) : null;

                if (\is_string($href) && str_starts_with($href, 'https://')) {
                    return $href;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payment
     *
     * @return array<mixed>|null
     */
    private function findSettledTransaction(array $payment): ?array
    {
        foreach (\is_array($payment['transactions'] ?? null) ? $payment['transactions'] : [] as $transaction) {
            if (!\is_array($transaction)) {
                continue;
            }

            // result.status is the observed shape; a flat status is tolerated.
            $status = $transaction['result']['status'] ?? $transaction['status'] ?? null;

            if (\is_string($status) && self::TRANSACTION_COMPLETED === strtoupper($status)) {
                return $transaction;
            }
        }

        return null;
    }

    private function scalarOrNull(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    /**
     * Generates the payment link signature as documented by CentralBill
     * (https://docs.centralbill.app/centralbill-payment-api/1-creer-le-lien-de-paiement): hex SHA-256 of
     * "applicationId,invoiceId,customerId,amount,currency,applicationSecret". This is a plain hash including the
     * secret, not an HMAC. The amount must be formatted exactly as sent in the link and in the API payload.
     */
    private function generatePaymentSignature(
        string $invoiceId,
        string $customerId,
        string $amount,
        string $currency,
    ): string {
        return hash('sha256', \sprintf(
            '%s,%s,%s,%s,%s,%s',
            $this->config->applicationId,
            $invoiceId,
            $customerId,
            $amount,
            $currency,
            $this->config->applicationSecret,
        ));
    }
}
