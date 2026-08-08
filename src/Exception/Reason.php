<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Exception;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
enum Reason
{
    case INVALID_SIGNATURE;
    case MISSING_SIGNATURE;
    case UNSUPPORTED_ALGORITHM;
    case MALFORMED_PAYLOAD;
    case MISSING_FIELD;
    case EXPIRED_REQUEST;
    case REPLAY_DETECTED;
    case UNKNOWN;
}
