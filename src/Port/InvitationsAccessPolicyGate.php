<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessCheckerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see InvitationsAccessPolicyInterface} (1.46.0) where the bundle asks the deprecated
 * {@see InvitationsAccessCheckerInterface}. Only `true` allows; exceptions propagate. Built per call by {@see Ports}.
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
        return $this->policy->allowsManage(AuthUserRefs::fromUser($user), $profile) === true;
    }
}
