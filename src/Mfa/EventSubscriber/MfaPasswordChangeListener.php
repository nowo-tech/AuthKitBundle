<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\EventSubscriber;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;
use function array_values;
use function spl_object_id;

/**
 * A changed password forgets the user's trusted devices (1.34, DD-L2; CONTRACT-MFA §6), also without UserKit: the
 * `password` field of an MFA user changed in a flush (account page, reset link or code, admin, CLI) → after the flush
 * {@see MfaManager::forgetDevices()} with reason `password_changed`. Holds the users of one flush only (reset between
 * requests in worker mode).
 */
final class MfaPasswordChangeListener implements ResetInterface
{
    /** @var array<int, UserInterface> */
    private array $changed = [];

    public function __construct(
        private readonly MfaManager $manager,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof UserInterface && array_key_exists('password', $unitOfWork->getEntityChangeSet($entity)) && $this->manager->profileFor($entity) instanceof ProfileSettings) {
                // @igor-ignore - Users of the current flush, emptied in postFlush and by reset().
                $this->changed[spl_object_id($entity)] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $users = array_values($this->changed);
        $this->reset();
        foreach ($users as $user) {
            $this->manager->forgetDevices($user, 'password_changed');
        }
    }

    public function reset(): void
    {
        $this->changed = [];
    }
}
