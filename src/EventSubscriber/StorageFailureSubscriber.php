<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\EventSubscriber;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_int;
use function is_string;
use function str_starts_with;

/**
 * The answer of AuthKit's own pages to a lock conflict (REQ-RUNTIME-003 rule 6): a deadlock, a lock wait timeout, a
 * serialization failure or an AuthKit named lock not obtained ({@see TransientStorageFailure} from AuthKit's
 * transaction runner, or a transient failure the runner did not see) is never a 500.
 *
 * 1.53.0: the answer is **409** (configurable to 503, the answer of 1.50–1.52, with `nowo_auth_kit.conflict_response`
 * or, when AuthKit's options are unset, `nowo_transaction_doctrine.conflict_response`) and carries a conflict code
 * ({@see ConflictCode}):
 * - `CONFLICT_001` with `Retry-After`: nothing of the request was committed, sending it again is safe;
 * - `CONFLICT_002` without `Retry-After`: part of the request was already committed (a commit counted by
 *   transaction-doctrine's {@see CommitLedger} since the request started, or a host write unit that gave up after a
 *   commit): the client reads again instead of repeating it.
 *
 * Pages get an {@see HttpException} with the policy's status (the original as previous), rendered by the application's
 * error page; a request that prefers JSON gets `{ok: false, error: "unavailable", code: "CONFLICT_00x"}` (the keys of
 * 1.50, `code` added). It runs at priority -64, after Symfony logged the failure, so the storage error stays in the
 * logs; the client never sees SQL or a driver message.
 *
 * 1.53.0: a unique key another request took first and an optimistic lock are no longer mapped (they are not lock
 * conflicts): AuthKit catches its "only one" races where they happen (docs/CONCURRENCY.md), anything else is a real
 * error.
 *
 * AuthKit's pages are its routes (`nowo_auth_kit_*`) and any route served by an AuthKit controller (a profile may rename
 * its routes). The application's own routes are left alone. Stateless: the commit count at the start of the request
 * is kept on the request.
 */
final class StorageFailureSubscriber implements EventSubscriberInterface
{
    /** Route names of AuthKit's controllers. */
    public const ROUTE_PREFIX = 'nowo_auth_kit_';

    /** Retry-After when neither the failure nor the policy says otherwise. */
    public const RETRY_AFTER = ConflictResponsePolicy::DEFAULT_RETRY_AFTER;

    /** JSON `error` of every conflict answer (unchanged since 1.50; the code tells them apart). */
    public const JSON_ERROR = 'unavailable';

    /** Message of a CONFLICT_002 answer (client-safe). */
    public const COMMITTED_MESSAGE = 'Part of the request was already saved. Reload the page before trying again.';

    /** Request attribute holding the commit count when the request started. */
    public const COMMITS_ATTRIBUTE = '_nowo_auth_kit_commits';

    /** Before the firewall and the controllers, after transaction-doctrine's worker hygiene (4096). */
    public const REQUEST_PRIORITY = 2048;

    /** Namespace of AuthKit's controllers. */
    private const CONTROLLER_PREFIX = 'Nowo\\AuthKitBundle\\';

    private readonly TransientFailureClassifier $classifier;

    private readonly ConflictResponsePolicy $policy;

    /**
     * @param ConflictResponsePolicy|null $policy Status and Retry-After; null: 409 + Retry-After 1
     * @param CommitLedger|null $ledger The commits of the process (transaction-doctrine's commit tracking); null: only
     *                                  ConcurrencyConflict::$committed tells a CONFLICT_002
     */
    public function __construct(?ConflictResponsePolicy $policy = null, private readonly ?CommitLedger $ledger = null)
    {
        $this->classifier = new TransientFailureClassifier();
        $this->policy     = $policy ?? new ConflictResponsePolicy();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest', self::REQUEST_PRIORITY],
            KernelEvents::EXCEPTION => ['onException', -64],
        ];
    }

    /**
     * Remembers the commit count when the request starts (a sub-request keeps the count of its own start).
     */
    public function onRequest(RequestEvent $event): void
    {
        $attributes = $event->getRequest()->attributes;
        if ($this->ledger instanceof CommitLedger && !$attributes->has(self::COMMITS_ATTRIBUTE)) {
            $attributes->set(self::COMMITS_ATTRIBUTE, $this->ledger->count());
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $error   = $event->getThrowable();
        if ($error instanceof HttpExceptionInterface || !$this->isAuthKitRequest($request)) {
            return;
        }
        $hint = $this->transientHint($error);
        if ($hint === false) {
            return;
        }
        $code = $this->committed($request, $error) ? ConflictCode::Committed : ConflictCode::RetrySafe;
        // 2.0 (REQ-RUNTIME-003 rule 6): X-Conflict-Code on every conflict answer; Retry-After only for CONFLICT_001.
        $headers = $this->policy->headersFor($code, $hint);
        if ($request->getPreferredFormat() !== 'json') {
            $event->setThrowable($this->policy->httpExceptionFor($code, $code->retrySafe() ? TransientStorageFailure::DEFAULT_MESSAGE : self::COMMITTED_MESSAGE, $error, $hint));

            return;
        }
        $response = new JsonResponse(['ok' => false, 'error' => self::JSON_ERROR, 'code' => $code->value], $this->policy->status, $headers);
        $response->headers->set('Cache-Control', 'no-store, private');
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
     * @return false|int|null The Retry-After hint of a lock conflict (null: none, the policy's value applies); false
     *                        when the failure is not a lock conflict
     */
    private function transientHint(Throwable $error): int|false|null
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds();
            }
        }

        return $this->classifier->isTransient($error) ? null : false;
    }

    /**
     * Whether part of the request was already committed: a host write unit said so, or the ledger counted a commit
     * since the request started.
     */
    private function committed(Request $request, Throwable $error): bool
    {
        if (ConflictCode::forFailure($error) === ConflictCode::Committed) {
            return true;
        }
        $before = $request->attributes->get(self::COMMITS_ATTRIBUTE);

        return $this->ledger instanceof CommitLedger && is_int($before) && $this->ledger->count() > $before;
    }
}
