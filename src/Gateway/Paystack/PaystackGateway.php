<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\Paystack;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\CallbackResult;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\DTO\PaymentResponse;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\IgnoredCallbackException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\PaymentGatewayInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Paystack payment gateway. Documentation: https://paystack.com/docs/payments/accept-payments/.
 *
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final class PaystackGateway implements PaymentGatewayInterface
{
    private PaystackSignatureVerifier $signatureVerifier;

    public function __construct(
        private readonly PaystackConfig $config,
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->signatureVerifier = new PaystackSignatureVerifier($this->config->secretKey);
    }

    public static function getName(): string
    {
        return 'paystack';
    }

    public function isConfigured(): bool
    {
        return !empty($this->config->secretKey);
    }

    /**
     * Initializes a Paystack transaction via the REST API. Returns a PaymentResponse with the authorization_url to which redirect the client.
     *
     * {@inheritdoc}
     *
     * @throws GatewayException If the gateway is not configured or returns an error
     */
    public function initiate(PaymentRequest $request): PaymentResponse
    {
        if (!$this->isConfigured()) {
            throw GatewayException::configurationError(self::getName(), 'secretKey is empty.');
        }

        $body = array_filter([
            'email' => $request->customerId,
            'amount' => (string) (int) round((float) $request->amount * 100),
            'currency' => $request->currency->value,
            'reference' => $request->invoiceId,
            'callback_url' => $request->callbackUrl ?? $this->config->defaultCallbackUrl,
            'metadata' => array_filter(
                array_merge($request->metadata, [
                    'name' => $request->customerName,
                    'description' => $request->description,
                ]),
                static fn ($v) => null !== $v,
            ),
        ], static fn ($v) => null !== $v && '' !== $v && [] !== $v);

        [$statusCode, $data] = $this->callApi('POST', '/transaction/initialize', ['json' => $body]);

        if (200 !== $statusCode || !($data['status'] ?? false)) {
            throw GatewayException::httpError(self::getName(), $statusCode, (string) ($data['message'] ?? 'Unknown error'));
        }

        $authUrl = $data['data']['authorization_url'] ?? null;
        $gatewayReference = $data['data']['reference'] ?? $request->invoiceId;
        $accessCode = $data['data']['access_code'] ?? null;

        if (!$authUrl) {
            throw GatewayException::unexpectedResponse(self::getName(), 'Missing authorization_url in response.', $data);
        }

        return new PaymentResponse(
            status: PaymentStatus::INITIATED,
            invoiceId: $request->invoiceId,
            gatewayTransactionId: $gatewayReference,
            redirectUrl: $authUrl,
            gatewayToken: $accessCode,
            rawResponse: $data,
        );
    }

    /**
     * Validates the HMAC-SHA512 signature of a Paystack webhook.
     *
     * {@inheritdoc}
     *
     * @throws InvalidCallbackException If the signature is missing or invalid
     */
    public function validateSignature(CallbackPayload $payload): void
    {
        $this->signatureVerifier->verify(
            $payload->getHeaders(),
            $payload->getBody(),
        );
    }

    /**
     * Processes the JSON payload of a Paystack webhook.
     *
     * {@inheritdoc}
     *
     * - "charge.success": status mapped from data.status (SUCCESS, PENDING, CANCELLED or FAILED);
     * - "refund.processed": REFUNDED, for the transaction referenced by data.transaction_reference;
     * - any other event: IgnoredCallbackException (to be acknowledged with HTTP 200, no state change).
     *
     * @throws IgnoredCallbackException If the event does not affect the payment status
     * @throws InvalidCallbackException If the payload is malformed or incomplete
     */
    public function handle(CallbackPayload $payload): CallbackResult
    {
        $body = json_decode($payload->getBody(), true);

        if (!\is_array($body)) {
            throw InvalidCallbackException::malformedPayload('Invalid JSON body.');
        }

        $event = $body['event'] ?? null;

        if (!\is_string($event) || '' === $event) {
            throw InvalidCallbackException::missingField('event');
        }

        // Only these events change the state of a payment; everything else (transfers, disputes,
        // subscriptions, other refund steps...) is acknowledged without being interpreted.
        if (!\in_array($event, [PaystackStatus::EVENT_CHARGE_SUCCESS, PaystackStatus::EVENT_REFUND_PROCESSED], true)) {
            throw IgnoredCallbackException::unsupportedEvent(self::getName(), $event);
        }

        $data = $body['data'] ?? null;

        if (!\is_array($data)) {
            throw InvalidCallbackException::missingField('data');
        }

        return PaystackStatus::EVENT_REFUND_PROCESSED === $event
            ? $this->handleRefundProcessed($data, $body)
            : $this->handleChargeSuccess($data, $body);
    }

    /**
     * @param array<mixed>         $data
     * @param array<string, mixed> $body
     *
     * @throws InvalidCallbackException
     */
    private function handleChargeSuccess(array $data, array $body): CallbackResult
    {
        $reference = $data['reference'] ?? null;
        $paystackId = $data['id'] ?? null;
        $paystackStatus = $data['status'] ?? null;

        if (!$reference) {
            throw InvalidCallbackException::missingField('data.reference');
        }
        if (!$paystackId) {
            throw InvalidCallbackException::missingField('data.id');
        }
        if (!\is_string($paystackStatus) || '' === $paystackStatus) {
            throw InvalidCallbackException::missingField('data.status');
        }

        $status = PaystackStatus::toPaymentStatus($paystackStatus);

        return new CallbackResult(
            status: $status,
            invoiceId: (string) $reference,
            gatewayTransactionId: (string) $paystackId,
            amount: $this->toMajorUnits($data['amount'] ?? null),
            errorMessage: $status->isSuccessful()
                ? null
                : \sprintf('Paystack event "%s" with status "%s".', PaystackStatus::EVENT_CHARGE_SUCCESS, $paystackStatus),
            rawPayload: $body,
        );
    }

    /**
     * A processed refund. Payload documented by Paystack (https://paystack.com/docs/payments/webhooks/):
     * {"status", "transaction_reference", "refund_reference", "amount" (string, subunits), "currency", ...},
     * without "id". "refund_reference" may be null: the transaction reference is then used as identifier,
     * so that an authentic refund is never rejected (a rejection would be retried by Paystack for 72 hours).
     * "reference" and "id" are accepted as fallbacks. The amount is the refunded amount.
     *
     * @param array<mixed>         $data
     * @param array<string, mixed> $body
     *
     * @throws InvalidCallbackException
     */
    private function handleRefundProcessed(array $data, array $body): CallbackResult
    {
        $transactionReference = $data['transaction_reference'] ?? $data['reference'] ?? null;

        if (!$transactionReference) {
            throw InvalidCallbackException::missingField('data.transaction_reference');
        }

        $refundReference = $data['refund_reference'] ?? $data['id'] ?? $transactionReference;

        return new CallbackResult(
            status: PaymentStatus::REFUNDED,
            invoiceId: (string) $transactionReference,
            gatewayTransactionId: (string) $refundReference,
            amount: $this->toMajorUnits($data['amount'] ?? null),
            rawPayload: $body,
        );
    }

    /**
     * Converts an amount in subunits (kobo, pesewas, cents...) into a decimal string in the main unit.
     */
    private function toMajorUnits(mixed $amount): ?string
    {
        return is_numeric($amount) ? number_format((float) $amount / 100, 2, '.', '') : null;
    }

    /**
     * Queries the Paystack API for the current status of a transaction. GET https://api.paystack.co/transaction/verify/:reference.
     *
     * {@inheritdoc}
     *
     * A technical failure (network, HTTP error, invalid key, unknown reference, non-JSON body) never
     * yields a payment status: it throws, so that callers do not mark a paid order as failed.
     *
     * @throws GatewayException If the API call fails or the response cannot be interpreted
     */
    public function getTransactionStatus(string $gatewayTransactionId): PaymentStatus
    {
        if (!$this->isConfigured()) {
            throw GatewayException::configurationError(self::getName(), 'secretKey is empty.');
        }

        [$statusCode, $data] = $this->callApi('GET', '/transaction/verify/'.rawurlencode($gatewayTransactionId));

        if (200 !== $statusCode || !($data['status'] ?? false)) {
            throw GatewayException::httpError(self::getName(), $statusCode, (string) ($data['message'] ?? 'Unknown error'));
        }

        $transactionStatus = $data['data']['status'] ?? null;

        if (!\is_string($transactionStatus) || '' === $transactionStatus) {
            throw GatewayException::unexpectedResponse(self::getName(), 'Missing data.status in verify response.', $data);
        }

        return PaystackStatus::toPaymentStatus($transactionStatus);
    }

    /**
     * Performs an authenticated call to the Paystack API.
     *
     * @param array<string, mixed> $options
     *
     * @return array{int, array<string, mixed>} HTTP status code and decoded JSON body
     *
     * @throws GatewayException On network failure or non-JSON response
     */
    private function callApi(string $method, string $path, array $options = []): array
    {
        $statusCode = 0;

        try {
            $response = $this->httpClient->request($method, $this->config->apiBaseUrl.$path, $options + [
                'auth_bearer' => $this->config->secretKey,
                'timeout' => $this->config->timeout,
            ]);

            $statusCode = $response->getStatusCode();
            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw GatewayException::networkError(self::getName(), $e);
        } catch (DecodingExceptionInterface $e) {
            throw GatewayException::unexpectedResponse(self::getName(), \sprintf('Non-JSON response (HTTP %d).', $statusCode));
        }

        return [$statusCode, $data];
    }
}
