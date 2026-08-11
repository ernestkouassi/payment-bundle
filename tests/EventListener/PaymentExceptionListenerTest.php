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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(PaymentExceptionListener::class)]
final class PaymentExceptionListenerTest extends TestCase
{
    private KernelInterface $kernel;

    private TranslatorInterface $translator;

    private PaymentExceptionListener $listener;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->translator
            ->method('trans')
            ->willReturnCallback(static fn (string $key) => $key);

        $this->listener = new PaymentExceptionListener(
            new NullLogger(),
            $this->kernel,
            $this->translator,
        );
    }

    #[Test]
    public function itIgnoresExceptionsOutsidePaymentRoutes(): void
    {
        $event = $this->makeEvent(new \RuntimeException('Other'), '/api/users');

        $this->listener->onKernelException($event);

        $this->assertFalse($event->hasResponse());
    }

    #[Test]
    public function itIgnoresNonPaymentExceptionsOnPaymentRoutes(): void
    {
        $event = $this->makeEvent(new \RuntimeException('Other'), '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertFalse($event->hasResponse());
    }

    #[Test]
    public function itHandlesGatewayDeclinedException(): void
    {
        $e = GatewayException::declined('centralbill', 'insufficient funds');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('gateway.declined', $event->getResponse());
    }

    #[Test]
    public function itHandlesGatewayHttpError(): void
    {
        $e = GatewayException::httpError('centralbill', 503, 'Service Unavailable');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('gateway.http_error', $event->getResponse());
    }

    #[Test]
    public function itHandlesGatewayNetworkError(): void
    {
        $e = GatewayException::networkError('centralbill', new \RuntimeException('timeout'));
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function itHandlesInvalidSignatureWith400(): void
    {
        $e = InvalidCallbackException::invalidSignature('HMAC mismatch');
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('callback.invalid_signature', $event->getResponse());
    }

    #[Test]
    public function itHandlesReplayDetectedWith200(): void
    {
        $e = InvalidCallbackException::replayDetected('tx-123');
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_OK, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('callback.replay_detected', $event->getResponse());
    }

    #[Test]
    public function itHandlesMissingSignatureWith400(): void
    {
        $e = InvalidCallbackException::missingSignature();
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function itHandlesGatewayNotFoundWith500(): void
    {
        $e = GatewayNotFoundException::forName('unknown', ['centralbill']);
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $event->getResponse()->getStatusCode());
        $this->assertResponseContains('gateway.not_found', $event->getResponse());
    }

    #[Test]
    public function itMasks5xxMessageInProduction(): void
    {
        $this->kernel->method('isDebug')->willReturn(false);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with('error.internal', [], 'E2KPaymentBundle')
            ->willReturn('An error occurred while processing the payment. Please try again.');

        $listener = new PaymentExceptionListener(new NullLogger(), $this->kernel, $translator);
        $event = $this->makeEvent(
            GatewayException::configurationError('centralbill', 'secret is empty'),
            '/payments/initiate',
        );

        $listener->onKernelException($event);

        $this->assertTrue($event->hasResponse());

        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertSame(
            'An error occurred while processing the payment. Please try again.',
            $body['error']['message'],
        );
        $this->assertArrayNotHasKey('details', $body['error']);
    }

    #[Test]
    public function itCallsTranslatorForServiceUnavailable(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with('error.service_unavailable', [], 'E2KPaymentBundle')
            ->willReturn('Le service de paiement est temporairement indisponible.');

        $listener = new PaymentExceptionListener(new NullLogger(), $this->kernel, $translator);

        $event = $this->makeEvent(
            GatewayNotFoundException::forName('unknown'),
            '/payments/initiate',
        );

        $listener->onKernelException($event);

        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertSame(
            'Le service de paiement est temporairement indisponible.',
            $body['error']['message'],
        );
    }

    #[Test]
    public function itShowsDetailsInDebugMode(): void
    {
        $this->kernel->method('isDebug')->willReturn(true);

        $e = GatewayException::declined('centralbill', 'refused');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $body = json_decode($event->getResponse()->getContent(), true);
        $this->assertArrayHasKey('details', $body['error']);
        $this->assertArrayHasKey('exception', $body['error']['details']);
    }

    private function makeEvent(\Throwable $exception, string $path): ExceptionEvent
    {
        $httpKernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path);

        return new ExceptionEvent($httpKernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    private function assertResponseContains(string $expected, Response $response): void
    {
        $this->assertStringContainsString($expected, $response->getContent());
    }
}
