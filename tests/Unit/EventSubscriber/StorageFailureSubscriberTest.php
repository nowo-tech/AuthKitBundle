<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Nowo\AuthKitBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * 1.50.0 (Beacon spec 239): AuthKit's pages answer a storage failure with 503 + Retry-After (transient) or 409
 * (conflict), never a 500, and never show SQL; the application's routes are left alone.
 */
final class StorageFailureSubscriberTest extends TestCase
{
    public function testItRunsAfterSymfonyLoggedTheFailure(): void
    {
        self::assertSame([KernelEvents::EXCEPTION => ['onException', -64]], StorageFailureSubscriber::getSubscribedEvents());
    }

    public function testATransientFailureOnAnAuthKitPageIsA503WithRetryAfter(): void
    {
        $failure = new TransientStorageFailure(3, TransientStorageFailure::DEFAULT_MESSAGE, $this->driver(DeadlockException::class));
        $event   = $this->dispatch(new RuntimeException('wrapped', 0, $failure), ['_route' => 'nowo_auth_kit_register']);

        $error = $event->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $error);
        self::assertSame(3, $error->getHeaders()['Retry-After']);
        self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $error->getMessage(), 'no SQL');
    }

    public function testADeadlockTheRunnerDidNotSeeIsTransientToo(): void
    {
        $event = $this->dispatch($this->driver(DeadlockException::class), ['_controller' => 'Nowo\\AuthKitBundle\\Controller\\RegisterController::register']);

        $error = $event->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $error);
        self::assertSame(1, $error->getHeaders()['Retry-After']);
    }

    public function testATransientFailureWithoutHintRetriesAfterOneSecond(): void
    {
        $event = $this->dispatch(new TransientStorageFailure(), ['_route' => 'nowo_auth_kit_login']);

        $error = $event->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $error);
        self::assertSame(StorageFailureSubscriber::RETRY_AFTER, $error->getHeaders()['Retry-After']);
    }

    public function testAUniqueKeyTakenMeanwhileIsA409(): void
    {
        $event = $this->dispatch($this->driver(UniqueConstraintViolationException::class), ['_route' => 'nowo_auth_kit_invitations_new']);

        self::assertInstanceOf(ConflictHttpException::class, $event->getThrowable());
    }

    public function testAnOptimisticLockThatLostIsA409ButNotOneWrappingAFailedCommit(): void
    {
        self::assertInstanceOf(ConflictHttpException::class, $this->dispatch(new OptimisticLockException('stale', null), ['_route' => 'nowo_auth_kit_mfa_enable'])->getThrowable());

        $unknownOutcome = new OptimisticLockException('commit failed', null, new RuntimeException('connection lost'));
        self::assertSame($unknownOutcome, $this->dispatch($unknownOutcome, ['_route' => 'nowo_auth_kit_mfa_enable'])->getThrowable());
    }

    public function testJsonClientsGetTheErrorCodeAndTheHeaders(): void
    {
        $event = $this->dispatch(new TransientStorageFailure(2), ['_route' => 'nowo_auth_kit_qr_login_status'], 'json');

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"ok":false,"error":"unavailable"}', $response->getContent());
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $conflict = $this->dispatch($this->driver(UniqueConstraintViolationException::class), ['_route' => 'nowo_auth_kit_qr_login_status'], 'json')->getResponse();
        self::assertInstanceOf(JsonResponse::class, $conflict);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('{"ok":false,"error":"conflict"}', $conflict->getContent());
        self::assertFalse($conflict->headers->has('Retry-After'));
    }

    public function testTheApplicationsRoutesHttpErrorsAndOtherFailuresAreLeftAlone(): void
    {
        $transient = new TransientStorageFailure(1);
        self::assertSame($transient, $this->dispatch($transient, ['_route' => 'app_checkout'])->getThrowable());
        self::assertSame($transient, $this->dispatch($transient, [])->getThrowable());

        $denied = new AccessDeniedHttpException();
        self::assertSame($denied, $this->dispatch($denied, ['_route' => 'nowo_auth_kit_login'])->getThrowable());

        $other = new RuntimeException('a bug');
        $event = $this->dispatch($other, ['_route' => 'nowo_auth_kit_login']);
        self::assertSame($other, $event->getThrowable());
        self::assertNull($event->getResponse());
    }

    /**
     * @param array<string, string> $attributes
     */
    private function dispatch(Throwable $error, array $attributes, ?string $format = null): ExceptionEvent
    {
        $request = Request::create('/');
        $request->attributes->add($attributes);
        if ($format === 'json') {
            $request->headers->set('Accept', 'application/json');
        }
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $error);
        (new StorageFailureSubscriber())->onException($event);

        return $event;
    }

    /**
     * @param class-string<DeadlockException|UniqueConstraintViolationException> $class
     */
    private function driver(string $class): DeadlockException|UniqueConstraintViolationException
    {
        return new $class(new class('driver message') extends AbstractException {
        }, null);
    }
}
