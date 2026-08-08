<?php

declare(strict_types=1);

namespace E2K\PaymentBundle;

use E2K\PaymentBundle\Enum\PaymentStatus;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 *
 * Main contract — every gateway MUST implement this interface. It comprises the initiation and management of the callback.
 *
 * The implementation of this interface is sufficient to automatically register the gateway in the registry (via registerForAutoconfiguration in the bundle).
 */
interface PaymentGatewayInterface extends
    PaymentInitiatorInterface,
    CallbackHandlerInterface
{
    /**
     * Unique gateway identifier (snake_case). Used as a key in the ServiceLocator. Ex: 'centralbill', 'wave', 'orange_money'
     */
    public static function getName(): string;

    /**
     * Checks if the gateway is correctly configured (API keys present, valid URLs, etc.).
     */
    public function isConfigured(): bool;

    /**
     * Returns the current status of a transaction from the gateway (polling).
     */
    public function getTransactionStatus(string $gatewayTransactionId): PaymentStatus;
}
