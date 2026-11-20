<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 *
 * Encapsulates the raw HTTP callback request to isolate it from the transport.
 */
final readonly class CallbackPayload
{
    public function __construct(
        public Request $httpRequest,
        /** Name of the gateway resolved from the route */
        public ?string $gatewayName = null,
    ) {
    }

    public function getBody(): string
    {
        return $this->httpRequest->getContent();
    }

    /**
     * @return array<string, list<string|null>>
     */
    public function getHeaders(): array
    {
        return $this->httpRequest->headers->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->httpRequest->query->all();
    }
}
