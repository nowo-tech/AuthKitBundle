<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Binds AuthKit's private entity manager ({@see AuthKitEntityManager}, 1.46.0, `doctrine.private_entity_manager: true`)
 * to one unit of work, also when the services resetter does not run between units of work (a host that removes or
 * bypasses `services_resetter`, `messenger:consume --no-reset`, long-running console processes; REQ-RUNTIME-002
 * rule 4). At each OUTERMOST boundary the private manager is dropped (its identity map cleared), so no AuthKit row
 * loaded for one request, message or command is served to the next one, and a manager closed by a failed write is
 * never reused.
 *
 * Boundaries, judged by {@see UnitOfWorkBoundaries} (which tracks the running units at the extreme priorities):
 * - HTTP: the main `kernel.request` (priority 4096) and `kernel.terminate` (priority -1024), only outside a console
 *   command and a Messenger message. Sub-requests share their main request's unit of work;
 * - Messenger: when a message is received (priority 4096) and after it is handled or failed (priority -1024);
 * - console: when the OUTERMOST command starts and terminates. A command run in-process from another command, a
 *   message handler or a controller does not drop the outer unit's manager.
 *
 * Only the bundle's own manager is ever touched: the application's entity managers are never cleared here (that is
 * the host's job, REQ-RUNTIME-002 rule 7). The manager arrives as an iterator of an IGNORE_ON_UNINITIALIZED_REFERENCE
 * reference, so a boundary never builds it.
 *
 * @internal
 */
final readonly class PrivateEntityManagerSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<ResetInterface> $resettables the private entity manager (skipped while uninitialized)
     */
    public function __construct(
        private UnitOfWorkBoundaries $boundaries,
        private iterable $resettables = [],
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                                   => ['onRequest', 4096],
            KernelEvents::TERMINATE                                 => ['onTerminate', -1024],
            UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_RECEIVED => ['clearState', 4096],
            UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_HANDLED  => ['clearState', -1024],
            UnitOfWorkBoundaries::MESSENGER_WORKER_MESSAGE_FAILED   => ['clearState', -1024],
            UnitOfWorkBoundaries::CONSOLE_COMMAND                   => ['onConsoleBoundary', 4096],
            UnitOfWorkBoundaries::CONSOLE_TERMINATE                 => ['onConsoleBoundary', -1024],
        ];
    }

    /**
     * Start of a main request: drops the manager only outside a command and a message.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->boundaries->isHttpOutermost()) {
            $this->clearState();
        }
    }

    /**
     * End of a request: drops the manager only outside a command and a message (an in-process HttpKernel::handle()).
     */
    public function onTerminate(): void
    {
        if ($this->boundaries->isHttpOutermost()) {
            $this->clearState();
        }
    }

    /**
     * Start or end of a command: drops the manager only for the outermost command.
     */
    public function onConsoleBoundary(object $event): void
    {
        if ($this->boundaries->isOutermost($event)) {
            $this->clearState();
        }
    }

    /**
     * Drops the bundle's private entity manager (never an application manager).
     */
    public function clearState(): void
    {
        foreach ($this->resettables as $service) {
            $service->reset();
        }
    }
}
