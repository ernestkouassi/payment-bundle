<?php
declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\Paystack;

use E2K\PaymentBundle\Exception\InvalidCallbackException;

/**
 * Verify the HMAC-SHA512 signature of Paystack webhooks.
 *
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class PaystackSignatureVerifier
{
    private const SIGNATURE_HEADER = 'x-paystack-signature';

    /**
     * @param string $secretKey
     */
    public function __construct(private string $secretKey)
    {
    }

    /**
     * Verify the HMAC-SHA512 signature of a Paystack webhook.
     *
     * @param array<string, string|string[]> $headers
     * @param string $rawBody
     *
     * @throws InvalidCallbackException If the signature is missing or invalid
     */
    public function verify(array $headers, string $rawBody): void
    {
        $headers = array_map(
            static fn($v) => is_array($v) ? implode(', ', $v) : $v,
            array_change_key_case($headers, CASE_LOWER),
        );

        $signature = $headers[self::SIGNATURE_HEADER] ?? null;

        if (null === $signature || '' === $signature) {
            throw InvalidCallbackException::missingSignature();
        }

        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);

        if (!hash_equals($expected, $signature)) {
            throw InvalidCallbackException::invalidSignature(
                'HMAC-SHA512 mismatch on x-paystack-signature',
                ['expected_length' => strlen($expected), 'received_length' => strlen($signature)],
            );
        }
    }
}
