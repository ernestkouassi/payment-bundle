<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Http;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
enum PaymentErrorCode: string
{
    case GATEWAY_MISCONFIGURED = 'gateway.misconfigured';
    case GATEWAY_HTTP_ERROR = 'gateway.http_error';
    case GATEWAY_NETWORK_ERROR = 'gateway.network_error';
    case GATEWAY_UNEXPECTED = 'gateway.unexpected_response';
    case GATEWAY_DECLINED = 'gateway.declined';
    case CALLBACK_INVALID_SIGNATURE = 'callback.invalid_signature';
    case CALLBACK_MISSING_SIGNATURE = 'callback.missing_signature';
    case CALLBACK_BAD_ALGORITHM = 'callback.unsupported_algorithm';
    case CALLBACK_MALFORMED = 'callback.malformed_payload';
    case CALLBACK_MISSING_FIELD = 'callback.missing_field';
    case CALLBACK_EXPIRED = 'callback.expired_request';
    case CALLBACK_REPLAY = 'callback.replay_detected';
    case GATEWAY_NOT_FOUND = 'gateway.not_found';
    case GATEWAY_NOT_CONFIGURED = 'gateway.not_configured';
    case INTERNAL_ERROR = 'payment.internal_error';
}
