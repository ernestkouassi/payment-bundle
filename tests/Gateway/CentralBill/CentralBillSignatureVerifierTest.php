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

    private CentralBillSignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new CentralBillSignatureVerifier(self::SECRET);
    }

    #[Test]
    public function itAcceptsAValidHmacSignature(): void
    {
        $this->verifier->verify($this->signedHeaders(gmdate(\DATE_RFC7231)), self::METHOD, self::URI);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRejectsASignedDateOlderThanTheTolerance(): void
    {
        $headers = $this->signedHeaders(gmdate(\DATE_RFC7231, time() - 301));

        try {
            $this->verifier->verify($headers, self::METHOD, self::URI);
            $this->fail('Expected InvalidCallbackException was not thrown.');
        } catch (InvalidCallbackException $e) {
            $this->assertSame(Reason::EXPIRED_REQUEST, $e->getReason());
        }
    }

    #[Test]
    public function itRejectsASignedDateTooFarInTheFuture(): void
    {
        $this->expectException(InvalidCallbackException::class);
        $this->expectExceptionMessage('exceeds tolerance');

        $this->verifier->verify($this->signedHeaders(gmdate(\DATE_RFC7231, time() + 3600)), self::METHOD, self::URI);
    }

    #[Test]
    public function itAcceptsADateWithinACustomTolerance(): void
    {
        $verifier = new CentralBillSignatureVerifier(self::SECRET, 3600);

        $verifier->verify($this->signedHeaders(gmdate(\DATE_RFC7231, time() - 1800)), self::METHOD, self::URI);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itSkipsTheFreshnessCheckWhenToleranceIsZero(): void
    {
        $verifier = new CentralBillSignatureVerifier(self::SECRET, 0);

        $verifier->verify($this->signedHeaders('Thu, 01 Dec 2022 19:08:22 +0000'), self::METHOD, self::URI);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRejectsAnUnparseableSignedDate(): void
    {
        try {
            $this->verifier->verify($this->signedHeaders('not-a-date'), self::METHOD, self::URI);
            $this->fail('Expected InvalidCallbackException was not thrown.');
        } catch (InvalidCallbackException $e) {
            $this->assertSame(Reason::MALFORMED_PAYLOAD, $e->getReason());
        }
    }

    #[Test]
    public function itChecksTheSignatureBeforeTheDate(): void
    {
        $headers = $this->signedHeaders('Thu, 01 Dec 2022 19:08:22 +0000');
        $headers['digest'] = 'SHA256=tampered';

        try {
            $this->verifier->verify($headers, self::METHOD, self::URI);
            $this->fail('Expected InvalidCallbackException was not thrown.');
        } catch (InvalidCallbackException $e) {
            $this->assertSame(Reason::INVALID_SIGNATURE, $e->getReason());
        }
    }

    #[Test]
    public function itRejectsMissingSignatureHeader(): void
    {
        $this->expectException(InvalidCallbackException::class);
        $this->expectExceptionMessage('Signature header is absent');

        $this->verifier->verify([], self::METHOD, self::URI);
    }

    #[Test]
    public function itRejectsUnsupportedAlgorithm(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $headers = [
            'signature' => 'keyId="id",algorithm="rsa-sha256",headers="date",signature="xyz"',
            'date' => 'Thu, 01 Dec 2022 19:08:22 +0000',
        ];

        $this->verifier->verify($headers, self::METHOD, self::URI);
    }

    #[Test]
    public function itRejectsMissingSignatureComponent(): void
    {
        $this->expectException(InvalidCallbackException::class);
        $headers = [
            'signature' => 'keyId="id",algorithm="hmac-sha256",headers="date"',
            'date' => 'Thu, 01 Dec 2022 19:08:22 +0000',
        ];

        $this->verifier->verify($headers, self::METHOD, self::URI);
    }

    #[Test]
    public function itRejectsInvalidHmac(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $headers = [
            'date' => 'Thu, 01 Dec 2022 19:08:22 +0000',
            'signature' => 'keyId="id",algorithm="hmac-sha256",headers="date",signature="invalidsignature=="',
        ];

        $this->verifier->verify($headers, self::METHOD, self::URI);
    }

    #[Test]
    public function itRejectsCallbackWithMissingReferencedHeader(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $sig = base64_encode(hash_hmac('sha256', 'date: some-date', self::SECRET, true));

        // 'content-type' est listé dans headers mais absent des en-têtes HTTP
        $headers = [
            'date' => 'some-date',
            'signature' => \sprintf(
                'keyId="id",algorithm="hmac-sha256",headers="date content-type",signature="%s"',
                $sig,
            ),
        ];

        $this->verifier->verify($headers, self::METHOD, self::URI);
    }

    #[Test]
    public function itHasInvalidSignatureReasonOnHmacMismatch(): void
    {
        $headers = [
            'date' => 'Thu, 01 Dec 2022 19:08:22 +0000',
            'signature' => 'keyId="id",algorithm="hmac-sha256",headers="date",signature="badsig=="',
        ];

        try {
            $this->verifier->verify($headers, self::METHOD, self::URI);
            $this->fail('Expected InvalidCallbackException was not thrown.');
        } catch (InvalidCallbackException $e) {
            $this->assertSame(Reason::INVALID_SIGNATURE, $e->getReason());
        }
    }

    /**
     * Builds a correctly signed set of headers for the given Date value.
     *
     * @return array<string, string>
     */
    private function signedHeaders(string $date): array
    {
        $contentType = 'application/json';
        $digest = 'SHA256=abc123';

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
}
