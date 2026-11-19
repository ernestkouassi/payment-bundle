<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Gateway\Paystack;

use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\Exception\Reason;
use E2K\PaymentBundle\Gateway\Paystack\PaystackSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(PaystackSignatureVerifier::class)]
final class PaystackSignatureVerifierTest extends TestCase
{
    private const SECRET = 'sk_test_secret123';

    private const WEBHOOK_SECRET = 'whsec_test_4f2a9c';

    private PaystackSignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PaystackSignatureVerifier(self::SECRET);
    }

    #[Test]
    public function itAcceptsAValidHmacSha512Signature(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"ORDER-42","status":"success"}}';
        $signature = hash_hmac('sha512', $body, self::SECRET);

        $this->verifier->verify(['x-paystack-signature' => $signature], $body);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itAcceptsHeaderWithDifferentCasing(): void
    {
        $body = '{"event":"charge.success"}';
        $signature = hash_hmac('sha512', $body, self::SECRET);

        $this->verifier->verify(['X-Paystack-Signature' => $signature], $body);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRejectsMissingSignatureHeader(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $this->verifier->verify([], '{"event":"charge.success"}');
    }

    #[Test]
    public function itHasMissingSignatureReasonWhenHeaderAbsent(): void
    {
        try {
            $this->verifier->verify([], '{}');
            $this->fail('Expected InvalidCallbackException');
        } catch (InvalidCallbackException $e) {
            $this->assertSame(Reason::MISSING_SIGNATURE, $e->getReason());
        }
    }

    #[Test]
    public function itRejectsInvalidSignature(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $this->verifier->verify(
            ['x-paystack-signature' => 'invalidsignature'],
            '{"event":"charge.success"}',
        );
    }

    #[Test]
    public function itHasInvalidSignatureReasonOnMismatch(): void
    {
        try {
            $this->verifier->verify(
                ['x-paystack-signature' => str_repeat('a', 128)],
                '{"event":"charge.success"}',
            );
            $this->fail('Expected InvalidCallbackException');
        } catch (InvalidCallbackException $e) {
            $this->assertSame(Reason::INVALID_SIGNATURE, $e->getReason());
        }
    }

    #[Test]
    public function itRejectsEmptySignature(): void
    {
        $this->expectException(InvalidCallbackException::class);

        $this->verifier->verify(
            ['x-paystack-signature' => ''],
            '{"event":"charge.success"}',
        );
    }

    #[Test]
    public function itRejectsSignatureComputedWithWrongKey(): void
    {
        $body = '{"event":"charge.success"}';
        $signature = hash_hmac('sha512', $body, 'wrong-secret');

        $this->expectException(InvalidCallbackException::class);
        $this->verifier->verify(['x-paystack-signature' => $signature], $body);
    }

    #[Test]
    public function itRejectsSignatureComputedOnDifferentBody(): void
    {
        $signature = hash_hmac('sha512', '{"other":"body"}', self::SECRET);

        $this->expectException(InvalidCallbackException::class);
        $this->verifier->verify(
            ['x-paystack-signature' => $signature],
            '{"event":"charge.success"}',
        );
    }

    // ---- "Paystack-Signature" (webhook signing secret) ----

    #[Test]
    public function itAcceptsAValidPaystackSignatureHeader(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"ORDER-42"}}';

        $this->signedVerifier()->verify(['Paystack-Signature' => $this->signedHeader($body)], $body);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itAcceptsAnyMatchingV1DuringSecretRotation(): void
    {
        $body = '{"event":"charge.success"}';
        $t = (string) time();
        $old = hash_hmac('sha512', $t.'.'.$body, 'whsec_old');
        $new = hash_hmac('sha512', $t.'.'.$body, self::WEBHOOK_SECRET);

        $this->signedVerifier()->verify(
            ['paystack-signature' => "t={$t},v1=whk_old:{$old},v1=whk_new:{$new}"],
            $body,
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itIgnoresUnknownFieldsOfThePaystackSignatureHeader(): void
    {
        $body = '{"event":"charge.success"}';
        $t = (string) time();
        $sig = hash_hmac('sha512', $t.'.'.$body, self::WEBHOOK_SECRET);

        $this->signedVerifier()->verify(['paystack-signature' => "v0=legacy, t={$t}, v2=future, v1=whk_abc:{$sig}"], $body);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRejectsAPaystackSignatureComputedOnAnotherBody(): void
    {
        $this->assertRejected(
            Reason::INVALID_SIGNATURE,
            ['paystack-signature' => $this->signedHeader('{"event":"charge.success","data":{"amount":100}}')],
            '{"event":"charge.success","data":{"amount":100000}}',
        );
    }

    #[Test]
    public function itRejectsAPaystackSignatureWithAForgedTimestamp(): void
    {
        $body = '{"event":"charge.success"}';
        $oldT = (string) (time() - 3600);
        $sig = hash_hmac('sha512', $oldT.'.'.$body, self::WEBHOOK_SECRET);

        // Replaying with a fresh "t" but the old signature must fail: "t" is part of the signed payload.
        $this->assertRejected(Reason::INVALID_SIGNATURE, ['paystack-signature' => 't='.time().",v1=whk_a:{$sig}"], $body);
    }

    #[Test]
    public function itRejectsAPaystackSignatureOlderThanTheTolerance(): void
    {
        $body = '{"event":"charge.success"}';

        $this->assertRejected(Reason::EXPIRED_REQUEST, ['paystack-signature' => $this->signedHeader($body, time() - 301)], $body);
    }

    #[Test]
    public function itRejectsAPaystackSignatureTooFarInTheFuture(): void
    {
        $body = '{"event":"charge.success"}';

        $this->assertRejected(Reason::EXPIRED_REQUEST, ['paystack-signature' => $this->signedHeader($body, time() + 301)], $body);
    }

    #[Test]
    public function itSkipsTheTimestampCheckWhenToleranceIsZero(): void
    {
        $body = '{"event":"charge.success"}';
        $verifier = new PaystackSignatureVerifier(self::SECRET, self::WEBHOOK_SECRET, 0);

        $verifier->verify(['paystack-signature' => $this->signedHeader($body, time() - 86400)], $body);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRequiresThePaystackSignatureHeaderWhenAWebhookSecretIsConfigured(): void
    {
        $body = '{"event":"charge.success"}';

        // A valid legacy header must not be accepted as a fallback: it carries no timestamp.
        $this->assertRejected(
            Reason::MISSING_SIGNATURE,
            ['x-paystack-signature' => hash_hmac('sha512', $body, self::SECRET)],
            $body,
        );
    }

    #[Test]
    public function itRejectsAPaystackSignatureWithoutTimestamp(): void
    {
        $body = '{"event":"charge.success"}';
        $sig = hash_hmac('sha512', '.'.$body, self::WEBHOOK_SECRET);

        $this->assertRejected(Reason::MISSING_FIELD, ['paystack-signature' => "v1=whk_a:{$sig}"], $body);
        $this->assertRejected(Reason::MISSING_FIELD, ['paystack-signature' => "t=abc,v1=whk_a:{$sig}"], $body);
    }

    #[Test]
    public function itRejectsAPaystackSignatureWithoutV1(): void
    {
        $this->assertRejected(Reason::MISSING_FIELD, ['paystack-signature' => 't='.time()], '{}');
        $this->assertRejected(Reason::MISSING_FIELD, ['paystack-signature' => 't='.time().',v1=no-key-id'], '{}');
    }

    #[Test]
    public function itUsesTheLegacyHeaderWhenTheWebhookSecretIsEmpty(): void
    {
        // An unset environment variable resolves to an empty string.
        $body = '{"event":"charge.success"}';
        $verifier = new PaystackSignatureVerifier(self::SECRET, '');

        $verifier->verify(['x-paystack-signature' => hash_hmac('sha512', $body, self::SECRET)], $body);

        $this->addToAssertionCount(1);
    }

    private function signedVerifier(): PaystackSignatureVerifier
    {
        return new PaystackSignatureVerifier(self::SECRET, self::WEBHOOK_SECRET);
    }

    private function signedHeader(string $body, ?int $t = null): string
    {
        $t ??= time();

        return \sprintf('t=%d,v1=whk_3f9a1c2e:%s', $t, hash_hmac('sha512', $t.'.'.$body, self::WEBHOOK_SECRET));
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertRejected(Reason $reason, array $headers, string $body): void
    {
        try {
            $this->signedVerifier()->verify($headers, $body);
            $this->fail('Expected InvalidCallbackException was not thrown.');
        } catch (InvalidCallbackException $e) {
            $this->assertSame($reason, $e->getReason(), $e->getMessage());
        }
    }
}
