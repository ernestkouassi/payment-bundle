<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\Paystack;

use E2K\PaymentBundle\Exception\InvalidCallbackException;

/**
 * Verifies the origin of Paystack webhooks (https://paystack.com/docs/payments/webhooks/#signature-validation).
 *
 * Two schemes are supported:
 *
 * - "Paystack-Signature" (recommended), used when a webhook signing secret ("whsec_...") is configured:
 *     Paystack-Signature: t=1760270400,v1=whk_3f9a1c2e:9d1b...e47a
 *   "t" is the signing time (Unix seconds); each "v1" is "<key id>:<lowercase hex HMAC-SHA512 of "{t}.{raw body}">"
 *   computed with the whole signing secret. Several "v1" are sent while a secret is being rotated. Events whose
 *   "t" is more than the tolerance away from the server clock are rejected (Paystack re-signs retries with a new "t").
 *   When a signing secret is configured, this header is mandatory: falling back to the legacy header would let
 *   an attacker strip it to replay an old event.
 *
 * - "x-paystack-signature" (legacy), used when no signing secret is configured: lowercase hex HMAC-SHA512 of the
 *   raw body computed with the API secret key. It has no timestamp, hence no replay protection, and Paystack stops
 *   sending it once the secret key is deleted.
 *
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
final readonly class PaystackSignatureVerifier
{
    /** Maximum age (seconds) of a "Paystack-Signature" timestamp, as prescribed by Paystack. */
    public const DEFAULT_TOLERANCE = 300;

    private const LEGACY_SIGNATURE_HEADER = 'x-paystack-signature';

    private const SIGNATURE_HEADER = 'paystack-signature';

    /**
     * @param string      $secretKey     API secret key, used for the legacy "x-paystack-signature" header
     * @param string|null $webhookSecret Webhook signing secret ("whsec_..."); when set, "Paystack-Signature" is required
     * @param int         $tolerance     Maximum age in seconds of the "Paystack-Signature" timestamp. 0 disables the check.
     */
    public function __construct(
        private string $secretKey,
        private ?string $webhookSecret = null,
        private int $tolerance = self::DEFAULT_TOLERANCE,
    ) {
    }

    /**
     * Verify the HMAC-SHA512 signature of a Paystack webhook.
     *
     * @param array<string, string|list<string|null>|null> $headers
     *
     * @throws InvalidCallbackException If the signature is missing, invalid or expired
     */
    public function verify(array $headers, string $rawBody): void
    {
        $headers = array_map(
            static fn ($v) => \is_array($v) ? implode(', ', $v) : (string) $v,
            array_change_key_case($headers, \CASE_LOWER),
        );

        if (null !== $this->webhookSecret && '' !== $this->webhookSecret) {
            $this->verifySignedHeader($headers[self::SIGNATURE_HEADER] ?? '', $rawBody, $this->webhookSecret);

            return;
        }

        $this->verifyLegacyHeader($headers[self::LEGACY_SIGNATURE_HEADER] ?? '', $rawBody);
    }

    /**
     * @throws InvalidCallbackException
     */
    private function verifySignedHeader(string $header, string $rawBody, string $webhookSecret): void
    {
        if ('' === $header) {
            throw InvalidCallbackException::missingSignature();
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $field) {
            $parts = explode('=', trim($field), 2);

            if (2 !== \count($parts)) {
                continue;
            }

            [$name, $value] = $parts;

            if ('t' === $name) {
                $timestamp = $value;
            } elseif ('v1' === $name) {
                // "<key id>:<signature>": the key id only identifies the secret, the signature follows the first ":".
                $keyAndSignature = explode(':', $value, 2);
                if (2 === \count($keyAndSignature)) {
                    $signatures[] = $keyAndSignature[1];
                }
            }
            // Any other field is ignored, as required by Paystack.
        }

        if (null === $timestamp || !ctype_digit($timestamp)) {
            throw InvalidCallbackException::missingField('Paystack-Signature.t');
        }

        if ([] === $signatures) {
            throw InvalidCallbackException::missingField('Paystack-Signature.v1');
        }

        $expected = hash_hmac('sha512', $timestamp.'.'.$rawBody, $webhookSecret);

        $matched = false;
        foreach ($signatures as $signature) {
            // No early exit: every candidate is compared in constant time.
            $matched = hash_equals($expected, $signature) || $matched;
        }

        if (!$matched) {
            throw InvalidCallbackException::invalidSignature('HMAC-SHA512 mismatch on Paystack-Signature', ['signatures' => \count($signatures)]);
        }

        // Checked once the signature is valid, so that the timestamp is trusted.
        if (0 !== $this->tolerance && abs(time() - (int) $timestamp) > $this->tolerance) {
            throw InvalidCallbackException::expiredRequest($timestamp, $this->tolerance);
        }
    }

    /**
     * @throws InvalidCallbackException
     */
    private function verifyLegacyHeader(string $signature, string $rawBody): void
    {
        if ('' === $signature) {
            throw InvalidCallbackException::missingSignature();
        }

        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);

        if (!hash_equals($expected, $signature)) {
            throw InvalidCallbackException::invalidSignature('HMAC-SHA512 mismatch on x-paystack-signature', ['expected_length' => \strlen($expected), 'received_length' => \strlen($signature)]);
        }
    }
}
