<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface;
use Nowo\AuthKitBundle\Port\Internal\InvitationsAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see InvitationsAccessPolicyInterface} (1.46.0) where the bundle asks its internal
 * {@see InvitationsAccessCheckerInterface}. Only `true` allows; exceptions propagate. Built per call by {@see Ports} (2.0: the only way AuthKit asks the port).
 *
 * @internal
 */
final readonly class InvitationsAccessPolicyGate implements InvitationsAccessCheckerInterface
{
    public function __construct(private InvitationsAccessPolicyInterface $policy)
    {
    }

    public function canManage(UserInterface $user, string $profile): bool
    {
        return $this->policy->allowsManage(AuthUserRefs::fromUser($user), $profile);
    }
}
