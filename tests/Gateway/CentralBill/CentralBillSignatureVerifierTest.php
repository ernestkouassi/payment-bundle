<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Gateway\CentralBill;

use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\Exception\Reason;
use E2K\PaymentBundle\Gateway\CentralBill\CentralBillSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(CentralBillSignatureVerifier::class)]
final class CentralBillSignatureVerifierTest extends TestCase
{
    private const SECRET = 'test-secret';

    private const METHOD = 'post';

    private const URI = '/payments/callback/centralbill';

    private const BODY = '{"id":"tx-1","result":{"status":"FAILED"},"invoice":{"id":"INV-1"}}';

    private CentralBillSignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new CentralBillSignatureVerifier(self::SECRET);
    }

    #[Test]
    public function itAcceptsAValidHmacSignature(): void
    {
        $this->verifier->verify($this->signedHeaders(), self::METHOD, self::URI, self::BODY);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itAcceptsTheStandardBinaryDigestEncoding(): void
    {
        $digest = 'SHA-256='.base64_encode(hash('sha256', self::BODY, true));

        $this->verifier->verify($this->signedHeaders(digest: $digest), self::METHOD, self::URI, self::BODY);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itAcceptsTheSignatureInTheAuthorizationHeader(): void
    {
        $headers = $this->signedHeaders();
        $headers['authorization'] = 'Signature '.$headers['signature'];
        unset($headers['signature']);

        $this->verifier->verify($headers, self::METHOD, self::URI, self::BODY);
        $this->addToAssertionCount(1);
    }

    /**
     * Regression test for audit finding C1: the headers of an authentic IPN replayed with a forged body.
     */
    #[Test]
    public function itRejectsAForgedBodyReplayedWithTheHeadersOfAnAuthenticIpn(): void
    {
        $forged = '{"id":"tx-1","result":{"status":"COMPLETED"},"invoice":{"id":"INV-999","totalAmount":{"amount":1}}}';

        $this->assertRejected(Reason::INVALID_SIGNATURE, $this->signedHeaders(), $forged);
    }

    #[Test]
    public function itRejectsASignatureThatDoesNotCoverTheDigest(): void
    {
        $date = gmdate(\DATE_RFC7231);
        $signingString = implode("\n", [
            \sprintf('(request-target): %s %s', self::METHOD, self::URI),
            \sprintf('date: %s', $date),
        ]);
        $headers = [
            'date' => $date,
            'signature' => \sprintf(
                'keyId="app-id",algorithm="hmac-sha256",headers="(request-target) date",signature="%s"',
                base64_encode(hash_hmac('sha256', $signingString, self::SECRET, true)),
            ),
        ];

        $this->assertRejected(Reason::INVALID_SIGNATURE, $headers, '{"forged":true}');
    }

    #[Test]
    public function itRejectsAnUnsupportedDigestAlgorithm(): void
    {
        $digest = 'MD5='.base64_encode(md5(self::BODY, true));

        $this->assertRejected(Reason::INVALID_SIGNATURE, $this->signedHeaders(digest: $digest), self::BODY);
    }

    #[Test]
    public function itRejectsASignedDateOlderThanTheTolerance(): void
    {
        $this->assertRejected(Reason::EXPIRED_REQUEST, $this->signedHeaders(gmdate(\DATE_RFC7231, time() - 301)), self::BODY);
    }

    #[Test]
    public function itRejectsASignedDateTooFarInTheFuture(): void
    {
        $this->assertRejected(Reason::EXPIRED_REQUEST, $this->signedHeaders(gmdate(\DATE_RFC7231, time() + 3600)), self::BODY);
    }

    #[Test]
    public function itAcceptsADateWithinACustomTolerance(): void
    {
        $verifier = new CentralBillSignatureVerifier(self::SECRET, 3600);

        $verifier->verify($this->signedHeaders(gmdate(\DATE_RFC7231, time() - 1800)), self::METHOD, self::URI, self::BODY);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itSkipsTheFreshnessCheckWhenToleranceIsZero(): void
    {
        $verifier = new CentralBillSignatureVerifier(self::SECRET, 0);

        $verifier->verify($this->signedHeaders('Thu, 01 Dec 2022 19:08:22 +0000'), self::METHOD, self::URI, self::BODY);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRejectsAnUnparseableSignedDate(): void
    {
        $this->assertRejected(Reason::MALFORMED_PAYLOAD, $this->signedHeaders('not-a-date'), self::BODY);
    }

    #[Test]
    public function itChecksTheSignatureBeforeTheDate(): void
    {
        $headers = $this->signedHeaders('Thu, 01 Dec 2022 19:08:22 +0000');
        $headers['content-type'] = 'text/plain';

        $this->assertRejected(Reason::INVALID_SIGNATURE, $headers, self::BODY);
    }

    #[Test]
    public function itRejectsMissingSignatureHeader(): void
    {
        $this->assertRejected(Reason::MISSING_SIGNATURE, [], self::BODY);
        $this->assertRejected(Reason::MISSING_SIGNATURE, ['authorization' => 'Bearer token'], self::BODY);
    }

    #[Test]
    public function itRejectsUnsupportedAlgorithm(): void
    {
        $headers = [
            'signature' => 'keyId="id",algorithm="rsa-sha256",headers="date",signature="xyz"',
            'date' => 'Thu, 01 Dec 2022 19:08:22 +0000',
        ];

        $this->assertRejected(Reason::UNSUPPORTED_ALGORITHM, $headers, self::BODY);
    }

    #[Test]
    public function itRejectsMissingSignatureComponent(): void
    {
        $headers = [
            'signature' => 'keyId="id",algorithm="hmac-sha256",headers="date"',
            'date' => 'Thu, 01 Dec 2022 19:08:22 +0000',
        ];

        $this->assertRejected(Reason::MISSING_FIELD, $headers, self::BODY);
    }

    #[Test]
    public function itRejectsInvalidHmac(): void
    {
        $headers = $this->signedHeaders();
        $headers['signature'] = preg_replace('/signature="[^"]+"/', 'signature="badsig=="', $headers['signature']) ?? '';

        $this->assertRejected(Reason::INVALID_SIGNATURE, $headers, self::BODY);
    }

    #[Test]
    public function itRejectsCallbackWithMissingReferencedHeader(): void
    {
        $headers = $this->signedHeaders();
        unset($headers['content-type']);

        $this->assertRejected(Reason::MISSING_FIELD, $headers, self::BODY);
    }

    /**
     * Builds a correctly signed set of headers, with the digest encoded as in CentralBill's example
     * (base64 of the hex SHA-256 of the body).
     *
     * @return array<string, string>
     */
    private function signedHeaders(?string $date = null, ?string $digest = null): array
    {
        $date ??= gmdate(\DATE_RFC7231);
        $digest ??= 'SHA-256='.base64_encode(hash('sha256', self::BODY));
        $contentType = 'application/json';

        $signingString = implode("\n", [
            \sprintf('(request-target): %s %s', self::METHOD, self::URI),
            \sprintf('content-type: %s', $contentType),
            \sprintf('date: %s', $date),
            \sprintf('digest: %s', $digest),
        ]);

        $sig = base64_encode(hash_hmac('sha256', $signingString, self::SECRET, true));

        return [
            'content-type' => $contentType,
            'date' => $date,
            'digest' => $digest,
            'signature' => \sprintf(
                'keyId="app-id",algorithm="hmac-sha256",headers="(request-target) content-type date digest",signature="%s"',
                $sig,
            ),
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertRejected(Reason $reason, array $headers, string $body): void
    {
        try {
            $this->verifier->verify($headers, self::METHOD, self::URI, $body);
            $this->fail('Expected InvalidCallbackException was not thrown.');
        } catch (InvalidCallbackException $e) {
            $this->assertSame($reason, $e->getReason(), $e->getMessage());
        }
    }
}
