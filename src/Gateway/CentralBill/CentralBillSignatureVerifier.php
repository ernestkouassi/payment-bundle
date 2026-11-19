<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\CentralBill;

use E2K\PaymentBundle\Exception\InvalidCallbackException;

/**
 * Verifies the HMAC-SHA256 signature of CentralBill callbacks according to the HTTP Signature Authentication protocol.
 * Expected Signature Header:
 *  keyId="...", algorithm="hmac-sha256",
 *  headers="(request-target) content-type date digest",
 *  signature="<base64>".
 */
final readonly class CentralBillSignatureVerifier
{
    /** Default maximum age (seconds) of a signed "Date" header. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * @param int $tolerance Maximum age in seconds of the signed "Date" header. 0 disables the check.
     */
    public function __construct(
        private string $applicationSecret,
        private int $tolerance = self::DEFAULT_TOLERANCE,
    ) {
    }

    /**
     * @param array<string, string|list<string|null>|null> $headers    HTTP headers (will be normalized to lowercase)
     * @param string                                       $method     HTTP method in lowercase ("post")
     * @param string                                       $requestUri Request URI ("/payments/callback/centralbill")
     *
     * @throws InvalidCallbackException
     */
    public function verify(array $headers, string $method, string $requestUri): void
    {
        $headers = array_map(
            static fn ($v) => \is_array($v) ? implode(', ', $v) : (string) $v,
            array_change_key_case($headers, \CASE_LOWER),
        );

        $rawSignatureHeader = $headers['signature'] ?? null;

        if (null === $rawSignatureHeader || '' === $rawSignatureHeader) {
            throw InvalidCallbackException::missingSignature();
        }

        $components = $this->parseSignatureHeader($rawSignatureHeader);

        foreach (['keyid', 'algorithm', 'headers', 'signature'] as $required) {
            if (empty($components[$required])) {
                throw InvalidCallbackException::missingField("Signature.{$required}");
            }
        }

        if ('hmac-sha256' !== strtolower($components['algorithm'])) {
            throw InvalidCallbackException::unsupportedAlgorithm($components['algorithm']);
        }

        $signedHeaders = explode(' ', strtolower($components['headers']));
        $signingParts = [];

        foreach ($signedHeaders as $headerName) {
            if ('(request-target)' === $headerName) {
                $signingParts[] = \sprintf('(request-target): %s %s', strtolower($method), $requestUri);
            } else {
                if (!isset($headers[$headerName])) {
                    throw InvalidCallbackException::missingField($headerName);
                }
                $signingParts[] = \sprintf('%s: %s', $headerName, $headers[$headerName]);
            }
        }

        $signingString = implode("\n", $signingParts);
        $expectedSig = base64_encode(
            hash_hmac('sha256', $signingString, $this->applicationSecret, true),
        );

        if (!hash_equals($expectedSig, $components['signature'])) {
            throw InvalidCallbackException::invalidSignature('HMAC mismatch', ['signing_string' => $signingString]);
        }

        // Freshness is checked only once the signature is valid, so that the "Date" header is trusted.
        if (\in_array('date', $signedHeaders, true)) {
            $this->assertFreshDate($headers['date']);
        }
    }

    /**
     * Rejects IPNs whose signed "Date" header is older (or further in the future) than the tolerance,
     * which prevents a captured notification from being replayed indefinitely.
     *
     * @throws InvalidCallbackException
     */
    private function assertFreshDate(string $date): void
    {
        if (0 === $this->tolerance) {
            return;
        }

        $timestamp = strtotime($date);

        if (false === $timestamp) {
            throw InvalidCallbackException::malformedPayload(\sprintf('Unparseable "Date" header "%s".', $date));
        }

        if (abs(time() - $timestamp) > $this->tolerance) {
            throw InvalidCallbackException::expiredRequest($date, $this->tolerance);
        }
    }

    /**
     * Parse : keyId="...", algorithm="...", headers="...", signature="...".
     *
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $components = [];

        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $header) ?: [] as $part) {
            $parts = explode('=', trim($part), 2);

            if (2 !== \count($parts)) {
                continue;
            }

            $components[strtolower(trim($parts[0]))] = trim($parts[1], '"');
        }

        return $components;
    }
}
