<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Gateway\CentralBill;

use E2K\PaymentBundle\Exception\InvalidCallbackException;

/**
 * Verifies the HMAC-SHA256 signature of CentralBill callbacks according to the HTTP Signature Authentication protocol.
 * Expected Signature Header:
 *  keyId="...", algorithm="hmac-sha256",
 *  headers="(request-target) content-type date digest",
 *  signature="<base64>"
 */
final readonly class CentralBillSignatureVerifier
{
    public function __construct(
        private string $applicationSecret,
    )
    {
    }

    /**
     * @param array<string, string|string[]> $headers HTTP headers (will be normalized to lowercase)
     * @param string $method HTTP method in lowercase ("post")
     * @param string $requestUri Request URI ("/payments/callback/centralbill")
     *
     * @throws InvalidCallbackException
     */
    public function verify(array $headers, string $method, string $requestUri): void
    {
        $headers = array_map(
            static fn($v) => is_array($v) ? implode(', ', $v) : $v,
            array_change_key_case($headers, CASE_LOWER),
        );

        $rawSignatureHeader = $headers['signature'] ?? null;

        if (null === $rawSignatureHeader) {
            throw InvalidCallbackException::missingSignature();
        }

        $components = $this->parseSignatureHeader($rawSignatureHeader);

        foreach (['keyid', 'algorithm', 'headers', 'signature'] as $required) {
            if (empty($components[$required])) {
                throw InvalidCallbackException::missingField("Signature.$required");
            }
        }

        if ('hmac-sha256' !== strtolower($components['algorithm'])) {
            throw InvalidCallbackException::unsupportedAlgorithm($components['algorithm']);
        }

        $signedHeaders = explode(' ', strtolower($components['headers']));
        $signingParts = [];

        foreach ($signedHeaders as $headerName) {
            if ('(request-target)' === $headerName) {
                $signingParts[] = sprintf('(request-target): %s %s', strtolower($method), $requestUri);
            } else {
                if (!isset($headers[$headerName])) {
                    throw InvalidCallbackException::missingField($headerName);
                }
                $signingParts[] = sprintf('%s: %s', $headerName, $headers[$headerName]);
            }
        }

        $signingString = implode("\n", $signingParts);
        $expectedSig = base64_encode(
            hash_hmac('sha256', $signingString, $this->applicationSecret, true)
        );

        if (!hash_equals($expectedSig, $components['signature'])) {
            throw InvalidCallbackException::invalidSignature(
                'HMAC mismatch',
                ['signing_string' => $signingString],
            );
        }
    }

    /**
     * Parse : keyId="...", algorithm="...", headers="...", signature="..."
     *
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $components = [];

        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $header) as $part) {
            $parts = explode('=', trim($part), 2);

            if (2 !== count($parts)) {
                continue;
            }

            $components[strtolower(trim($parts[0]))] = trim($parts[1], '"');
        }

        return $components;
    }
}
