<?php

declare(strict_types=1);

namespace E2K\PaymentBundle;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\CallbackResult;
use E2K\PaymentBundle\Exception\InvalidCallbackException;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
interface CallbackHandlerInterface
{
    /**
     * Validates the signature/authenticity of the callback.
     *
     * @throws InvalidCallbackException If the signature is invalid.
     */
    public function validateSignature(CallbackPayload $payload): void;

    /**
     * Treats the callback and returns the normalized result.
     *
     * @throws InvalidCallbackException
     */
    public function handle(CallbackPayload $payload): CallbackResult;
}
