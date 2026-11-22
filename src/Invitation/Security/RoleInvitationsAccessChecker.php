<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default checker: the user must be granted at least one of `invitations.security.access_roles`. An empty list denies
 * everyone (fail closed; the configuration already refuses it).
 *
 * 2.0: on the port {@see InvitationsAccessPolicyInterface} (it implemented the removed InvitationsAccessCheckerInterface;
 * the roles are checked on the signed-in user through the authorization checker, as before).
 */
final readonly class RoleInvitationsAccessChecker implements InvitationsAccessPolicyInterface
{
    /**
     * @param list<string> $accessRoles
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    public function allowsManage(AuthUserRef $user, string $profile): bool
    {
        foreach ($this->accessRoles as $role) {
            if ($role !== '' && $this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
