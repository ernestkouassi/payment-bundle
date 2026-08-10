<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 *
 * Standardized JSON response for all payment errors.
 *
 * Format :
 * {
 *   "error": {
 *     "code":    "gateway.declined",
 *     "message": "Payment declined: insufficient funds.",
 *     "details": { ... }   //present only in debug mode
 *   }
 * }
 */
final class ApiErrorResponse extends JsonResponse
{
    public function __construct(
        PaymentErrorCode $code,
        string $message,
        int $httpStatus,
        array $details = [],
        bool $debug = false,
    ) {
        $body = [
            'error' => array_filter([
                'code' => $code->value,
                'message' => $message,
                'details' => $debug && ! empty($details) ? $details : null,
            ]),
        ];

        parent::__construct($body, $httpStatus);
    }
}
