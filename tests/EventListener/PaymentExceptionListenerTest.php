<?php
declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\EventListener;

use E2K\PaymentBundle\EventListener\PaymentExceptionListener;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\GatewayNotFoundException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(PaymentExceptionListener::class)]
final class PaymentExceptionListenerTest extends TestCase
{
    private KernelInterface          $kernel;
    private PaymentExceptionListener $listener;

    protected function setUp(): void
    {
        $this->kernel   = $this->createMock(KernelInterface::class);
        $this->listener = new PaymentExceptionListener(new NullLogger(), $this->kernel);
    }

    #[Test]
    public function it_ignores_exceptions_outside_payment_routes(): void
    {
        $event = $this->makeEvent(new \RuntimeException('Other'), '/api/users');

        $this->listener->onKernelException($event);

        $this->assertFalse($event->hasResponse());
    }

    #[Test]
    public function it_ignores_non_payment_exceptions_on_payment_routes(): void
    {
        $event = $this->makeEvent(new \RuntimeException('Other'), '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertFalse($event->hasResponse());
    }

    #[Test]
    public function it_handles_gateway_declined_exception(): void
    {
        $e     = GatewayException::declined('centralbill', 'insufficient funds');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('gateway.declined', $event->getResponse());
    }

    #[Test]
    public function it_handles_gateway_http_error(): void
    {
        $e     = GatewayException::httpError('centralbill', 503, 'Service Unavailable');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('gateway.http_error', $event->getResponse());
    }

    #[Test]
    public function it_handles_gateway_network_error(): void
    {
        $e     = GatewayException::networkError('centralbill', new \RuntimeException('timeout'));
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function it_handles_invalid_signature_with_400(): void
    {
        $e     = InvalidCallbackException::invalidSignature('HMAC mismatch');
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('callback.invalid_signature', $event->getResponse());
    }

    #[Test]
    public function it_handles_replay_detected_with_200(): void
    {
        $e     = InvalidCallbackException::replayDetected('tx-123');
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_OK, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('callback.replay_detected', $event->getResponse());
    }

    #[Test]
    public function it_handles_missing_signature_with_400(): void
    {
        $e     = InvalidCallbackException::missingSignature();
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function it_handles_gateway_not_found_with_500(): void
    {
        $e     = GatewayNotFoundException::forName('unknown', ['centralbill']);
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('gateway.not_found', $event->getResponse());
    }

    #[Test]
    public function it_masks_5xx_message_in_production(): void
    {
        $this->kernel->method('isDebug')->willReturn(false);

        $e     = GatewayException::configurationError('centralbill', 'secret is empty');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertStringContainsString('error occurred', $body['error']['message']);
        $this->assertArrayNotHasKey('details', $body['error']);
    }

    #[Test]
    public function it_shows_details_in_debug_mode(): void
    {
        $this->kernel->method('isDebug')->willReturn(true);

        $e     = GatewayException::declined('centralbill', 'refused');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertArrayHasKey('details', $body['error']);
        $this->assertArrayHasKey('exception', $body['error']['details']);
    }

    private function makeEvent(\Throwable $exception, string $path): ExceptionEvent
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $request    = Request::create($path);

        return new ExceptionEvent($httpKernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    private function assertResponseContains(string $expected, Response $response): void
    {
        $this->assertStringContainsString($expected, $response->getContent());
    }
}
