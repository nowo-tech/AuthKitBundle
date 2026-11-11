<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\EventSubscriber;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;
use function array_values;
use function spl_object_id;

/**
 * A changed password forgets the user's trusted devices (1.34, DD-L2; CONTRACT-MFA §6), also without UserKit: the
 * password field (`mfa.trusted_devices.password_field`, default `password`) of an MFA user changed in a flush (account page, reset link or code, admin, CLI) → after the flush
 * {@see MfaManager::forgetDevices()} with reason `password_changed`.
 *
 * Holds the users of the flush in progress only, per entity manager. FrankenPHP worker mode with the kernel kept alive
 * and NOT reset (REQ-RUNTIME-002): a flush that throws after `onFlush` never reaches `postFlush`, so the memo is also
 * emptied at the start of every `onFlush` of that manager and at each request / console / Messenger boundary
 * (`kernel.request` main request, `kernel.terminate`, `console.terminate`, Messenger worker events). `kernel.reset`
 * still empties it, but correctness never depends on it (1.45.1: before, a failed flush left the user in the memo and a
 * later flush of any other user forgot that user's trusted devices).
 */
final class MfaPasswordChangeListener implements EventSubscriberInterface, ResetInterface
{
    /** @var array<int, array<int, UserInterface>> users with a changed password, by entity manager object id */
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
        $manager    = $args->getObjectManager();
        $key        = spl_object_id($manager);
        $unitOfWork = $manager->getUnitOfWork();
        // A previous flush of this manager that threw after onFlush left its users here: never carry them over.
        unset($this->changed[$key]);
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $profile = $entity instanceof UserInterface ? $this->manager->profileFor($entity) : null;
            $field   = (string) ($profile?->mfa['trusted_devices']['password_field'] ?? 'password');
            if ($entity instanceof UserInterface && $profile instanceof ProfileSettings && array_key_exists($field, $unitOfWork->getEntityChangeSet($entity))) {
                // @igor-ignore - Users of the flush in progress, emptied in postFlush, at the next onFlush and at every request/console/Messenger boundary.
                $this->changed[$key][spl_object_id($entity)] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $key   = spl_object_id($args->getObjectManager());
        $users = array_values($this->changed[$key] ?? []);
        unset($this->changed[$key]);
        foreach ($users as $user) {
            $this->manager->forgetDevices($user, 'password_changed');
        }
    }

    public function reset(): void
    {
        $this->changed = [];
    }
}
