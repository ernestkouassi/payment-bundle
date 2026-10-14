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

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 *
 * Automatically registered in the registry via: registerForAutoconfiguration(PaymentGatewayInterface::class)->addTag('e2k_payment.gateway')
 */
final readonly class CentralBillGateway implements PaymentGatewayInterface
{
    private CentralBillSignatureVerifier $signatureVerifier;

    public function __construct(
        private CentralBillConfig $config,
    ) {
        $this->signatureVerifier = new CentralBillSignatureVerifier(
            $this->config->applicationSecret,
        );
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
     * @throws GatewayException
     */
    public function initiate(PaymentRequest $request): PaymentResponse
    {
        if (!$this->isConfigured()) {
            throw GatewayException::configurationError(self::getName(), 'applicationId or applicationSecret is empty.');
        }

        $issuedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $dueDate = (new \DateTimeImmutable('+7 days'))->format(\DateTimeInterface::ATOM);
        $expiresAt = (new \DateTimeImmutable('+30 days'))->format(\DateTimeInterface::ATOM);

        $signature = $this->generatePaymentSignature(
            invoiceId: $request->invoiceId,
            customerId: $request->customerId,
            amount: $request->amount,
            currency: $request->currency->value,
        );

        $params = array_filter([
            'applicationId' => $this->config->applicationId,
            'invoice[id]' => $request->invoiceId,
            'invoice[customerId]' => $request->customerId,
            'invoice[totalAmount][amount]' => $request->amount,
            'invoice[totalAmount][currency]' => $request->currency->value,
            'invoice[issuedAt]' => $issuedAt,
            'invoice[dueDate]' => $dueDate,
            'invoice[expiresAt]' => $expiresAt,
            'description' => $request->description ?? $request->invoiceId,
            'signature' => $signature,
            'callbackUrl' => $request->callbackUrl ?? $this->config->defaultCallbackUrl,
            'redirectUrl' => $request->returnUrl ?? $this->config->defaultRedirectUrl,
        ], static fn ($v) => null !== $v && '' !== $v);

        $redirectUrl = \sprintf(
            '%s/?%s',
            rtrim($this->config->webPayBaseUrl, '/'),
            http_build_query($params),
        );

        return new PaymentResponse(
            status: PaymentStatus::INITIATED,
            invoiceId: $request->invoiceId,
            redirectUrl: $redirectUrl,
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

    public function getTransactionStatus(string $gatewayTransactionId): PaymentStatus
    {
        return PaymentStatus::PENDING;
    }

    /**
     * Generates the HMAC-SHA256 signature for a payment request.
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
