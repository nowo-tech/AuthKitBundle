<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\EventSubscriber;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_string;
use function str_starts_with;

/**
 * 1.50.0 (Beacon spec 239): a storage failure that reaches one of AuthKit's own pages is never a 500. A transient one
 * (TransientStorageFailure from AuthKit's transaction runner, or a deadlock / lock wait / serialization failure the runner
 * did not see) answers **503** with `Retry-After`; a conflict (a unique key another request took first, an optimistic
 * lock) answers **409**. Pages get Symfony's ServiceUnavailableHttpException / ConflictHttpException (the original as
 * previous), rendered by the application's error page; a request that prefers JSON gets
 * `{ok: false, error: "unavailable" | "conflict"}`. It runs at priority -64, after Symfony logged the failure, so the
 * storage error stays in the logs; the client never sees SQL or a driver message.
 *
 * AuthKit's pages are its routes (`nowo_auth_kit_*`) and any route served by an AuthKit controller (a profile may rename
 * its routes). The application's own routes are left alone. Stateless.
 */
final class StorageFailureSubscriber implements EventSubscriberInterface
{
    /** Route names of AuthKit's controllers. */
    public const ROUTE_PREFIX = 'nowo_auth_kit_';

    /** Retry-After when the failure carries no hint. */
    public const RETRY_AFTER = 1;

    /** Namespace of AuthKit's controllers. */
    private const CONTROLLER_PREFIX = 'Nowo\\AuthKitBundle\\';

    private readonly TransientFailureClassifier $classifier;

    public function __construct()
    {
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $error   = $event->getThrowable();
        if ($error instanceof HttpExceptionInterface || !$this->isAuthKitRequest($request)) {
            return;
        }
        $retryAfter = $this->retryAfter($error);
        $conflict   = $retryAfter === null && $this->isConflict($error);
        if ($retryAfter === null && !$conflict) {
            return;
        }
        if ($request->getPreferredFormat() !== 'json') {
            $event->setThrowable($conflict
                ? new ConflictHttpException('The data changed meanwhile. Reload and try again.', $error)
                : new ServiceUnavailableHttpException($retryAfter, TransientStorageFailure::DEFAULT_MESSAGE, $error));

            return;
        }
        $response = new JsonResponse(['ok' => false, 'error' => $conflict ? 'conflict' : 'unavailable'], $conflict ? Response::HTTP_CONFLICT : Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Cache-Control', 'no-store, private');
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }
        $event->setResponse($response);
    }

    private function isAuthKitRequest(Request $request): bool
    {
        $route      = $request->attributes->get('_route');
        $controller = $request->attributes->get('_controller');

        return (is_string($route) && str_starts_with($route, self::ROUTE_PREFIX))
            || (is_string($controller) && str_starts_with($controller, self::CONTROLLER_PREFIX));
    }

    /**
     * @return int|null Seconds of the Retry-After of a transient failure; null when the failure is not transient
     */
    private function retryAfter(Throwable $error): ?int
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds() ?? self::RETRY_AFTER;
            }
        }

        return $this->classifier->isTransient($error) ? self::RETRY_AFTER : null;
    }

    /**
     * A unique key another request took first, or an optimistic lock that lost (not one that wraps a failed commit,
     * whose outcome is unknown).
     */
    private function isConflict(Throwable $error): bool
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
            if ($current instanceof OptimisticLockException) {
                return !$current->getPrevious() instanceof Throwable;
            }
        }

        return false;
    }
}
