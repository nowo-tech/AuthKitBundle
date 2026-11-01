<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default checker: the user must be granted at least one of `invitations.security.access_roles`. An empty list denies
 * everyone (fail closed; the configuration already refuses it).
 */
final readonly class RoleInvitationsAccessChecker implements InvitationsAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    public function canManage(UserInterface $user, string $profile): bool
    {
        foreach ($this->accessRoles as $role) {
            if ($role !== '' && $this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
