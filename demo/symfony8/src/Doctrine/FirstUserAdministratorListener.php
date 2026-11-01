<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * DEMO ONLY: the first account of the demo (registration_mode: first_user_only) becomes the administrator
 * (ROLE_ADMIN), so it can open the invitation administration (invitations.security.access_roles: [ROLE_ADMIN]).
 * Accounts created later, invited ones included, keep the registration_role.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: User::class)]
final class FirstUserAdministratorListener
{
    public function prePersist(User $user, PrePersistEventArgs $event): void
    {
        if ($event->getObjectManager()->getRepository(User::class)->count([]) === 0) {
            $user->setRoles([...$user->getRoles(), 'ROLE_ADMIN']);
        }
    }
}
