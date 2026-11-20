<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\CentralBill;

use E2K\PaymentBundle\Exception\InvalidCallbackException;

/**
 * Verifies CentralBill IPNs according to the HTTP Signature Authentication protocol
 * (https://docs.centralbill.app/centralbill-payment-api/2-notifications-ipn).
 *
 * Expected headers ("Signature: ..." or "Authorization: Signature ..."):
 *  keyId="...", algorithm="hmac-sha256",
 *  headers="(request-target) content-type date digest",
 *  signature="<base64 HMAC-SHA256 of the signing string, keyed with the application secret>"
 *  Digest: SHA-256=<base64>
 *
 * The signature only covers the "Digest" header, not the body itself: the body is authenticated by checking that
 * the signed "Digest" matches it. A signature that does not cover "digest" is therefore rejected. CentralBill's
 * example encodes the hex SHA-256 in base64; the standard RFC 3230 form (base64 of the binary hash) is accepted too.
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
     * @param string                                       $requestUri Request URI, including the query string ("/payments/callback/centralbill")
     * @param string                                       $body       Raw request body, checked against the signed "Digest" header
     *
     * @throws InvalidCallbackException
     */
    public function verify(array $headers, string $method, string $requestUri, string $body): void
    {
        $headers = array_map(
            static fn ($v) => \is_array($v) ? implode(', ', $v) : (string) $v,
            array_change_key_case($headers, \CASE_LOWER),
        );

        $rawSignatureHeader = $headers['signature'] ?? '';

        // The documented parameter is "Authorization: Signature keyId=...,..."; CentralBill also sends "Signature: ...".
        if ('' === $rawSignatureHeader && 0 === stripos($headers['authorization'] ?? '', 'Signature ')) {
            $rawSignatureHeader = substr($headers['authorization'], \strlen('Signature '));
        }

        if ('' === $rawSignatureHeader) {
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

        // Without a signed digest, the body is not authenticated at all: anybody holding one valid IPN could
        // replay its headers with an arbitrary body.
        if (!\in_array('digest', $signedHeaders, true)) {
            throw InvalidCallbackException::invalidSignature('The signature does not cover the "Digest" header.');
        }

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

        // The headers are now trusted: bind the body to the signed digest.
        $this->assertDigestMatchesBody($headers['digest'], $body);

        // Freshness is checked only once the signature is valid, so that the "Date" header is trusted.
        if (\in_array('date', $signedHeaders, true)) {
            $this->assertFreshDate($headers['date']);
        }
    }

    /**
     * @throws InvalidCallbackException
     */
    private function assertDigestMatchesBody(string $digestHeader, string $body): void
    {
        $parts = explode('=', $digestHeader, 2);

        if (2 !== \count($parts) || !\in_array(strtolower(trim($parts[0])), ['sha-256', 'sha256'], true)) {
            throw InvalidCallbackException::invalidSignature(\sprintf('Unsupported "Digest" header "%s".', $digestHeader));
        }

        $received = trim($parts[1]);
        $candidates = [
            base64_encode(hash('sha256', $body)),       // CentralBill example: base64 of the hex digest
            base64_encode(hash('sha256', $body, true)), // RFC 3230: base64 of the binary digest
        ];

        $matched = false;
        foreach ($candidates as $candidate) {
            $matched = hash_equals($candidate, $received) || $matched;
        }

        if (!$matched) {
            throw InvalidCallbackException::invalidSignature('The body does not match the signed "Digest" header.');
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
