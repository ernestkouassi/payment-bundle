<?php

declare(strict_types=1);

namespace E2K\PaymentBundle\Tests\EventListener;

use E2K\PaymentBundle\EventListener\PaymentExceptionListener;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\GatewayNotFoundException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @author Ernest KOUASSI <ernestkouassi02@gmail.com>
 */
#[CoversClass(PaymentExceptionListener::class)]
final class PaymentExceptionListenerTest extends TestCase
{
    private PaymentExceptionListener $listener;

    protected function setUp(): void
    {
        $this->listener = $this->makeListener();
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
    public function itNeverInterceptsAnythingWhenThePrefixIsEmpty(): void
    {
        $listener = $this->makeListener(prefix: '');
        $event = $this->makeEvent(GatewayException::declined('centralbill', 'refused'), '/payments/initiate');

        $listener->onKernelException($event);

        $this->assertFalse($event->hasResponse());
    }

    #[Test]
    public function itHonoursACustomPrefix(): void
    {
        $listener = $this->makeListener(prefix: '/billing/');
        $event = $this->makeEvent(GatewayException::declined('centralbill', 'refused'), '/billing/initiate');

        $listener->onKernelException($event);

        $this->assertTrue($event->hasResponse());
    }

    #[Test]
    public function itHandlesGatewayDeclinedException(): void
    {
        $e = GatewayException::declined('centralbill', 'insufficient funds');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $response = $this->response($event);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('gateway.declined', $this->json($response)['error']['code']);
    }

    #[Test]
    public function itHandlesGatewayHttpError(): void
    {
        $e = GatewayException::httpError('centralbill', 503, 'Service Unavailable');
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $response = $this->response($event);
        $this->assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        $this->assertSame('gateway.http_error', $this->json($response)['error']['code']);
    }

    #[Test]
    public function itHandlesGatewayNetworkError(): void
    {
        $e = GatewayException::networkError('centralbill', new \RuntimeException('timeout'));
        $event = $this->makeEvent($e, '/payments/initiate');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $this->response($event)->getStatusCode());
    }

    #[Test]
    public function itHandlesInvalidSignatureWith400(): void
    {
        $e = InvalidCallbackException::invalidSignature('HMAC mismatch');
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $response = $this->response($event);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('callback.invalid_signature', $this->json($response)['error']['code']);
    }

    #[Test]
    public function itHandlesExpiredRequestWith400(): void
    {
        $e = InvalidCallbackException::expiredRequest('Thu, 01 Dec 2022 19:08:22 +0000', 300);
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $response = $this->response($event);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('callback.expired_request', $this->json($response)['error']['code']);
    }

    #[Test]
    public function itHandlesReplayDetectedWith200AndLogsAWarning(): void
    {
        $logger = new RecordingLogger();
        $listener = $this->makeListener(logger: $logger);
        $event = $this->makeEvent(InvalidCallbackException::replayDetected('tx-123'), '/payments/callback/centralbill');

        $listener->onKernelException($event);

        $response = $this->response($event);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('callback.replay_detected', $this->json($response)['error']['code']);
        $this->assertSame([LogLevel::WARNING], array_column($logger->records, 0));
    }

    #[Test]
    public function itHandlesMissingSignatureWith400(): void
    {
        $e = InvalidCallbackException::missingSignature();
        $event = $this->makeEvent($e, '/payments/callback/centralbill');

        $this->listener->onKernelException($event);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->response($event)->getStatusCode());
    }

    #[Test]
    public function itReturns404AndLogsAWarningForAnUnknownRequestedGateway(): void
    {
        $logger = new RecordingLogger();
        $listener = $this->makeListener(logger: $logger);
        $event = $this->makeEvent(GatewayNotFoundException::forName('unknown', ['centralbill']), '/payments/callback/unknown');

        $listener->onKernelException($event);

        $response = $this->response($event);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame('gateway.not_found', $this->json($response)['error']['code']);
        $this->assertSame([LogLevel::WARNING], array_column($logger->records, 0));
    }

    #[Test]
    public function itReturns500AndLogsCriticalWhenTheDefaultGatewayIsMissing(): void
    {
        $logger = new RecordingLogger();
        $listener = $this->makeListener(logger: $logger);
        $event = $this->makeEvent(GatewayNotFoundException::noDefault('wave', ['centralbill']), '/payments/initiate');

        $listener->onKernelException($event);

        $response = $this->response($event);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame('gateway.not_found', $this->json($response)['error']['code']);
        $this->assertSame([LogLevel::CRITICAL], array_column($logger->records, 0));
    }

    #[Test]
    public function itReturns500WhenTheGatewayIsNotConfigured(): void
    {
        $event = $this->makeEvent(GatewayNotFoundException::notConfigured('centralbill'), '/payments/initiate');

        $this->listener->onKernelException($event);

        $response = $this->response($event);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame('gateway.not_configured', $this->json($response)['error']['code']);
    }

    #[Test]
    public function itMasks5xxMessageInProduction(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with('error.internal', [], 'E2KPaymentBundle')
            ->willReturn('An error occurred while processing the payment. Please try again.');

        $listener = new PaymentExceptionListener(new NullLogger(), false, $translator);
        $event = $this->makeEvent(
            GatewayException::configurationError('centralbill', 'secret is empty'),
            '/payments/initiate',
        );

        $listener->onKernelException($event);

        $body = $this->json($this->response($event));
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

        $listener = new PaymentExceptionListener(new NullLogger(), false, $translator);
        $event = $this->makeEvent(GatewayNotFoundException::notConfigured('centralbill'), '/payments/initiate');

        $listener->onKernelException($event);

        $this->assertSame(
            'Le service de paiement est temporairement indisponible.',
            $this->json($this->response($event))['error']['message'],
        );
    }

    #[Test]
    public function itFallsBackToEnglishMessagesWithoutTranslator(): void
    {
        $listener = new PaymentExceptionListener(null);
        $event = $this->makeEvent(GatewayNotFoundException::notConfigured('centralbill'), '/payments/initiate');

        $listener->onKernelException($event);

        $this->assertSame(
            'Payment service is currently unavailable.',
            $this->json($this->response($event))['error']['message'],
        );
    }

    #[Test]
    public function itShowsDetailsInDebugMode(): void
    {
        $listener = $this->makeListener(debug: true);
        $event = $this->makeEvent(GatewayException::declined('centralbill', 'refused'), '/payments/initiate');

        $listener->onKernelException($event);

        $body = $this->json($this->response($event));
        $this->assertArrayHasKey('details', $body['error']);
        $this->assertArrayHasKey('exception', $body['error']['details'] ?? []);
    }

    #[Test]
    public function itSubscribesToKernelExceptionWithPriority10(): void
    {
        $this->assertSame(
            ['kernel.exception' => [['onKernelException', 10]]],
            PaymentExceptionListener::getSubscribedEvents(),
        );
    }

    private function makeListener(
        ?LoggerInterface $logger = null,
        bool $debug = false,
        string $prefix = '/payments/',
    ): PaymentExceptionListener {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new PaymentExceptionListener($logger ?? new NullLogger(), $debug, $translator, $prefix);
    }

    private function makeEvent(\Throwable $exception, string $path): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    private function response(ExceptionEvent $event): Response
    {
        $response = $event->getResponse();

        if (null === $response) {
            $this->fail('The listener was expected to set a response.');
        }

        return $response;
    }

    /**
     * @return array{error: array{code: string, message: string, details?: array<string, mixed>}}
     */
    private function json(Response $response): array
    {
        $content = $response->getContent();

        if (false === $content) {
            $this->fail('The response has no content.');
        }

        /** @var array{error: array{code: string, message: string, details?: array<string, mixed>}} $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
