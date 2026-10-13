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
}
