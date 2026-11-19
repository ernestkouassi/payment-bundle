<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\Http;

use E2K\PaymentBundle\Http\ApiErrorResponse;
use E2K\PaymentBundle\Http\PaymentErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiErrorResponse::class)]
final class ApiErrorResponseTest extends TestCase
{
    #[Test]
    public function itRendersTheStandardErrorFormatWithoutDetailsOutsideDebug(): void
    {
        $response = new ApiErrorResponse(PaymentErrorCode::GATEWAY_DECLINED, 'Declined.', 422, ['file' => '/srv/app.php']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(
            '{"error":{"code":"gateway.declined","message":"Declined."}}',
            $response->getContent(),
        );
    }

    #[Test]
    public function itIncludesDetailsInDebugMode(): void
    {
        $response = new ApiErrorResponse(PaymentErrorCode::INTERNAL_ERROR, 'Boom.', 500, ['line' => 42], debug: true);

        $this->assertSame(
            '{"error":{"code":"payment.internal_error","message":"Boom.","details":{"line":42}}}',
            $response->getContent(),
        );
    }

    #[Test]
    public function itOmitsEmptyDetailsEvenInDebugMode(): void
    {
        $response = new ApiErrorResponse(PaymentErrorCode::INTERNAL_ERROR, 'Boom.', 500, debug: true);

        $this->assertSame('{"error":{"code":"payment.internal_error","message":"Boom."}}', $response->getContent());
    }
}
