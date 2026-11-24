<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Nowo\AuthKitBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * 1.53.0 (REQ-RUNTIME-003 rule 6): AuthKit's pages answer a lock conflict with 409 (503 per host) and a conflict code:
 * CONFLICT_001 + Retry-After when nothing was committed, CONFLICT_002 without Retry-After when part of the request was;
 * never a 500, never SQL. Unique violations, optimistic locks and lost connections are not lock conflicts, and the
 * application's routes are left alone.
 */
final class StorageFailureSubscriberTest extends TestCase
{
    public function testItSnapshotsTheCommitsBeforeTheFirewallAndAnswersAfterSymfonyLoggedTheFailure(): void
    {
        self::assertSame([
            KernelEvents::REQUEST   => ['onRequest', 2048],
            KernelEvents::EXCEPTION => ['onException', -64],
        ], StorageFailureSubscriber::getSubscribedEvents());
    }

    public function testATransientFailureOnAnAuthKitPageIsA409WithRetryAfterAndConflict001(): void
    {
        $failure = new TransientStorageFailure(3, TransientStorageFailure::DEFAULT_MESSAGE, $this->driver(DeadlockException::class));
        $event   = $this->dispatch(new RuntimeException('wrapped', 0, $failure), ['_route' => 'nowo_auth_kit_register']);

        $error = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $error);
        self::assertSame(409, $error->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '3'], $error->getHeaders());
        self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $error->getMessage(), 'no SQL');
    }

    public function testADeadlockALockWaitAndASerializationFailureTheRunnerDidNotSeeAreConflictsToo(): void
    {
        foreach ([$this->driver(DeadlockException::class), $this->driver(LockWaitTimeoutException::class), $this->serializationFailure()] as $failure) {
            $error = $this->dispatch($failure, ['_controller' => 'Nowo\\AuthKitBundle\\Controller\\RegisterController::register'])->getThrowable();
            self::assertInstanceOf(HttpException::class, $error);
            self::assertSame(409, $error->getStatusCode());
            self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $error->getHeaders());
        }
    }

    public function testATransientFailureWithoutHintUsesThePolicysRetryAfter(): void
    {
        $error = $this->dispatch(new TransientStorageFailure(), ['_route' => 'nowo_auth_kit_login'])->getThrowable();
        self::assertInstanceOf(HttpException::class, $error);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => (string) StorageFailureSubscriber::RETRY_AFTER], $error->getHeaders());

        $error = $this->dispatch(new TransientStorageFailure(), ['_route' => 'nowo_auth_kit_login'], null, new ConflictResponsePolicy(409, 7))->getThrowable();
        self::assertInstanceOf(HttpException::class, $error);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '7'], $error->getHeaders());
    }

    public function testAHostCanKeepThe503(): void
    {
        $policy = new ConflictResponsePolicy(ConflictResponsePolicy::STATUS_SERVICE_UNAVAILABLE);
        $error  = $this->dispatch(new TransientStorageFailure(2), ['_route' => 'nowo_auth_kit_login'], null, $policy)->getThrowable();
        self::assertInstanceOf(HttpException::class, $error);
        self::assertSame(503, $error->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '2'], $error->getHeaders());

        $response = $this->dispatch(new TransientStorageFailure(2), ['_route' => 'nowo_auth_kit_login'], 'json', $policy)->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_001"}', $response->getContent());
    }

    public function testJsonClientsGetTheExistingKeysTheCodeAndTheHeaders(): void
    {
        $response = $this->dispatch(new TransientStorageFailure(2), ['_route' => 'nowo_auth_kit_qr_login_status'], 'json')->getResponse();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_001"}', $response->getContent());
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testAHostWriteUnitThatGaveUpAfterACommitIsConflict002WithoutRetryAfter(): void
    {
        $conflict = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, $this->driver(DeadlockException::class), true);

        $error = $this->dispatch($conflict, ['_route' => 'nowo_auth_kit_register'])->getThrowable();
        self::assertInstanceOf(HttpException::class, $error);
        self::assertSame(409, $error->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $error->getHeaders(), 'the code, never Retry-After');
        self::assertSame(StorageFailureSubscriber::COMMITTED_MESSAGE, $error->getMessage());

        $response = $this->dispatch($conflict, ['_route' => 'nowo_auth_kit_register'], 'json')->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_002"}', $response->getContent());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));

        $retrySafe = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, $this->driver(DeadlockException::class));
        $response  = $this->dispatch($retrySafe, ['_route' => 'nowo_auth_kit_register'], 'json')->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_001"}', $response->getContent());
    }

    public function testACommitCountedSinceTheRequestStartedIsConflict002(): void
    {
        $ledger     = new CommitLedger();
        $subscriber = new StorageFailureSubscriber(null, $ledger);
        $request    = Request::create('/');
        $request->attributes->set('_route', 'nowo_auth_kit_qr_login_complete');
        $request->headers->set('Accept', 'application/json');
        $ledger->record();
        $subscriber->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(1, $request->attributes->get(StorageFailureSubscriber::COMMITS_ATTRIBUTE));

        // Nothing committed since the start: retry-safe.
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new TransientStorageFailure(1));
        $subscriber->onException($event);
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_001"}', $event->getResponse()?->getContent());

        // The snapshot is taken once per request; then a commit (the QR challenge consumed) before the conflict.
        $ledger->record();
        $subscriber->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $request->attributes->get(StorageFailureSubscriber::COMMITS_ATTRIBUTE));
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new TransientStorageFailure(1));
        $subscriber->onException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_002"}', $response->getContent());
        self::assertFalse($response->headers->has('Retry-After'));
    }

    /**
     * 2.2.1 (REQ-RUNTIME-003 rule 6, request-level detection): an AuthKit controller rendered as a fragment of a page
     * takes the count of the page's (main request's) start, so a commit made earlier in the page answers CONFLICT_002.
     */
    public function testASubRequestTakesTheMainRequestsCount(): void
    {
        $ledger     = new CommitLedger();
        $requests   = new RequestStack();
        $subscriber = new StorageFailureSubscriber(null, $ledger, $requests);
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $main       = Request::create('/page');
        $requests->push($main);
        $subscriber->onRequest(new RequestEvent($kernel, $main, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(0, $main->attributes->get(StorageFailureSubscriber::COMMITS_ATTRIBUTE));

        // The page commits (the host or another bundle), then renders an AuthKit fragment that meets a lock conflict.
        $ledger->record();
        $fragment = Request::create('/_fragment');
        $fragment->attributes->set('_route', 'nowo_auth_kit_login');
        $fragment->headers->set('Accept', 'application/json');
        $requests->push($fragment);
        $subscriber->onRequest(new RequestEvent($kernel, $fragment, HttpKernelInterface::SUB_REQUEST));
        self::assertSame(0, $fragment->attributes->get(StorageFailureSubscriber::COMMITS_ATTRIBUTE), 'the main request\'s count');

        $event = new ExceptionEvent($kernel, $fragment, HttpKernelInterface::SUB_REQUEST, new TransientStorageFailure(1));
        $subscriber->onException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_002"}', $response->getContent());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testASubRequestWithoutAMainCountTakesItsOwnStart(): void
    {
        $ledger     = new CommitLedger();
        $requests   = new RequestStack();
        $subscriber = new StorageFailureSubscriber(null, $ledger, $requests);
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $requests->push(Request::create('/page'));
        $ledger->record();
        $fragment = Request::create('/_fragment');
        $requests->push($fragment);
        $subscriber->onRequest(new RequestEvent($kernel, $fragment, HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $fragment->attributes->get(StorageFailureSubscriber::COMMITS_ATTRIBUTE));

        // Without a request stack (direct instantiation, 2.2.0 behaviour): its own start too.
        $other = Request::create('/_fragment');
        (new StorageFailureSubscriber(null, $ledger))->onRequest(new RequestEvent($kernel, $other, HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $other->attributes->get(StorageFailureSubscriber::COMMITS_ATTRIBUTE));
    }

    public function testWithoutALedgerOrASnapshotTheRequestIsRetrySafe(): void
    {
        $request = Request::create('/');
        (new StorageFailureSubscriber())->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($request->attributes->has(StorageFailureSubscriber::COMMITS_ATTRIBUTE));

        $ledger = new CommitLedger();
        $ledger->record();
        $error = $this->dispatch(new TransientStorageFailure(1), ['_route' => 'nowo_auth_kit_login'], null, null, $ledger)->getThrowable();
        self::assertInstanceOf(HttpException::class, $error);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $error->getHeaders(), 'no snapshot: nothing known to be committed');
    }

    public function testUniqueViolationsOptimisticLocksAndLostConnectionsAreNotConflicts(): void
    {
        $unique = $this->driver(UniqueConstraintViolationException::class);
        self::assertSame($unique, $this->dispatch($unique, ['_route' => 'nowo_auth_kit_invitations_new'])->getThrowable());
        self::assertNull($this->dispatch($unique, ['_route' => 'nowo_auth_kit_invitations_new'], 'json')->getResponse());

        $optimistic = new OptimisticLockException('stale', null);
        self::assertSame($optimistic, $this->dispatch($optimistic, ['_route' => 'nowo_auth_kit_mfa_enable'])->getThrowable());

        $lost = $this->driver(ConnectionLost::class);
        self::assertSame($lost, $this->dispatch($lost, ['_route' => 'nowo_auth_kit_login'])->getThrowable());
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
    private function dispatch(Throwable $error, array $attributes, ?string $format = null, ?ConflictResponsePolicy $policy = null, ?CommitLedger $ledger = null): ExceptionEvent
    {
        $request = Request::create('/');
        $request->attributes->add($attributes);
        if ($format === 'json') {
            $request->headers->set('Accept', 'application/json');
        }
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $error);
        (new StorageFailureSubscriber($policy, $ledger))->onException($event);

        return $event;
    }

    /**
     * @param class-string<ConnectionLost|DeadlockException|LockWaitTimeoutException|UniqueConstraintViolationException> $class
     */
    private function driver(string $class): ConnectionLost|DeadlockException|LockWaitTimeoutException|UniqueConstraintViolationException
    {
        return new $class(new class('driver message') extends AbstractException {
        }, null);
    }

    /** PostgreSQL 40001 (serialization_failure), as the driver reports it. */
    private function serializationFailure(): Throwable
    {
        return new class('could not serialize access', '40001') extends AbstractException {
        };
    }
}
