<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\EventSubscriber;

use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

use function array_key_exists;
use function array_values;
use function spl_object_id;

/**
 * A changed password forgets the user's trusted devices (1.34, DD-L2; CONTRACT-MFA §6), also without UserKit: the
 * password field (`mfa.trusted_devices.password_field`, default `password`) of an MFA user changed in a flush (account page, reset link or code, admin, CLI) → after the flush
 * {@see MfaManager::forgetDevices()} with reason `password_changed`.
 *
 * Holds the users of the flush in progress only, per unit of work (1.45.2, audit MD-A-L1: a nested flush of the same
 * unit of work, e.g. from a lifecycle callback, keeps the outer flush's users). FrankenPHP worker mode with the kernel
 * kept alive and NOT reset (REQ-RUNTIME-002): a flush that throws after `onFlush` never reaches `postFlush`; the failed
 * commit closes and clears the manager, so `onClear` drops that unit of work's users, an entry under a reused object id
 * of a unit of work that is gone is never carried over, and the memo is emptied at each request / console / Messenger
 * boundary (`kernel.request` main request, `kernel.terminate`, `console.terminate`, Messenger worker events). `kernel.reset`
 * still empties it, but correctness never depends on it (1.45.1: before, a failed flush left the user in the memo and a
 * later flush of any other user forgot that user's trusted devices).
 */
final class MfaPasswordChangeListener implements EventSubscriberInterface, ResetInterface
{
    /** @var array<int, array{uow: WeakReference<UnitOfWork>, users: array<int, UserInterface>}> users with a changed password, by unit of work object id */
    private array $changed = [];

    public function __construct(
        private readonly MfaManager $manager,
    ) {
    }

    /**
     * Request / console / Messenger boundaries (the Messenger and console events are plain names: both components are
     * optional, and an event nobody dispatches costs nothing).
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                                              => ['onKernelRequest', 4096],
            KernelEvents::TERMINATE                                            => ['reset', -4096],
            'console.terminate'                                                => ['reset', -4096],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent' => ['reset', 4096],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent'  => ['reset', -4096],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'   => ['reset', -4096],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        $key        = spl_object_id($unitOfWork);
        // An entry under a reused object id belongs to a unit of work that is gone (a failed flush, then a reset
        // manager): never carry it over. The same unit of work (a nested flush) keeps the outer flush's users.
        if (!isset($this->changed[$key]) || $this->changed[$key]['uow']->get() !== $unitOfWork) {
            // @igor-ignore - Users of the flush in progress, emptied in postFlush, onClear and at every request/console/Messenger boundary.
            $this->changed[$key] = ['uow' => WeakReference::create($unitOfWork), 'users' => []];
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $profile = $entity instanceof UserInterface ? $this->manager->profileFor($entity) : null;
            $field   = (string) ($profile?->mfa['trusted_devices']['password_field'] ?? 'password');
            if ($entity instanceof UserInterface && $profile instanceof ProfileSettings && array_key_exists($field, $unitOfWork->getEntityChangeSet($entity))) {
                // @igor-ignore - Same as above.
                $this->changed[$key]['users'][spl_object_id($entity)] = $entity;
            }
        }
        if ($this->changed[$key]['users'] === []) {
            unset($this->changed[$key]);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        $key        = spl_object_id($unitOfWork);
        $entry      = $this->changed[$key] ?? null;
        unset($this->changed[$key]);
        $users = $entry !== null && $entry['uow']->get() === $unitOfWork ? array_values($entry['users']) : [];
        foreach ($users as $user) {
            $this->manager->forgetDevices($user, 'password_changed');
        }
    }

    /**
     * The entity manager was cleared (or closed by a failed flush): its pending users go.
     */
    public function onClear(OnClearEventArgs $args): void
    {
        unset($this->changed[spl_object_id($args->getObjectManager()->getUnitOfWork())]);
    }

    public function reset(): void
    {
        $this->changed = [];
    }
}
