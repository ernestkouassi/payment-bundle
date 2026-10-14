<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\Paystack;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\CallbackResult;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\DTO\PaymentResponse;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\PaymentGatewayInterface;
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
            throw GatewayException::configurationError($this->getName(), 'secretKey is empty.');
        }

        $body = array_filter([
            'email' => $request->customerId,
            'amount' => (string) (int) round((float) $request->amount * 100),
            'currency' => $request->currency->value,
            'reference' => $request->invoiceId,
            'callback_url' => $request->callbackUrl ?? $this->config->defaultCallbackUrl,
            'metadata' => array_merge($request->metadata, [
                'name' => $request->customerName,
                'description' => $request->description,
            ]),
        ], static fn ($v) => null !== $v && '' !== $v && [] !== $v);

        try {
            $response = $this->httpClient->request('POST', $this->config->apiBaseUrl.'/transaction/initialize', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->config->secretKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(throw: false);
        } catch (TransportExceptionInterface $e) {
            throw GatewayException::networkError($this->getName(), $e);
        }

        if (200 !== $statusCode || !($data['status'] ?? false)) {
            throw GatewayException::httpError($this->getName(), $statusCode, $data['message'] ?? 'Unknown error');
        }

        $authUrl = $data['data']['authorization_url'] ?? null;
        $gatewayReference = $data['data']['reference'] ?? $request->invoiceId;
        $accessCode = $data['data']['access_code'] ?? null;

        if (!$authUrl) {
            throw GatewayException::unexpectedResponse($this->getName(), 'Missing authorization_url in response.', $data);
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
     * @throws InvalidCallbackException If the payload is malformed or incomplete
     */
    public function handle(CallbackPayload $payload): CallbackResult
    {
        $body = json_decode($payload->getBody(), true);

        if (!\is_array($body)) {
            throw InvalidCallbackException::malformedPayload('Invalid JSON body.');
        }

        $event = $body['event'] ?? null;
        $data = $body['data'] ?? null;

        if (!$event) {
            throw InvalidCallbackException::missingField('event');
        }

        if (!\is_array($data)) {
            throw InvalidCallbackException::missingField('data');
        }

        $reference = $data['reference'] ?? null;
        $paystackId = $data['id'] ?? null;
        $paystackStatus = $data['status'] ?? null;
        $amount = $data['amount'] ?? null;

        if (!$reference) {
            throw InvalidCallbackException::missingField('data.reference');
        }
        if (!$paystackId) {
            throw InvalidCallbackException::missingField('data.id');
        }
        if (!$paystackStatus) {
            throw InvalidCallbackException::missingField('data.status');
        }

        $status = (PaystackStatus::EVENT_CHARGE_SUCCESS === $event)
            ? PaystackStatus::toPaymentStatus($paystackStatus)
            : PaymentStatus::FAILED;

        $errorMessage = null;
        if (!$status->isSuccessful()) {
            $errorMessage = \sprintf(
                'Paystack event "%s" with status "%s".',
                $event,
                $paystackStatus,
            );
        }

        return new CallbackResult(
            status: $status,
            invoiceId: $reference,
            gatewayTransactionId: (string) $paystackId,
            amount: isset($amount) ? number_format($amount / 100, 2, '.', '') : null,
            errorMessage: $errorMessage,
            rawPayload: $body,
        );
    }

    /**
     * Queries the Paystack API for the current status of a transaction. GET https://api.paystack.co/transaction/verify/:reference.
     *
     * {@inheritdoc}
     *
     * @throws GatewayException If the API call fails
     */
    public function getTransactionStatus(string $gatewayTransactionId): PaymentStatus
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                $this->config->apiBaseUrl.'/transaction/verify/'.urlencode($gatewayTransactionId),
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->config->secretKey,
                    ],
                ],
            );

            $data = $response->toArray(throw: false);
        } catch (TransportExceptionInterface $e) {
            throw GatewayException::networkError($this->getName(), $e);
        }

        if (!($data['status'] ?? false)) {
            return PaymentStatus::FAILED;
        }

        return PaystackStatus::toPaymentStatus($data['data']['status'] ?? 'failed');
    }
}
