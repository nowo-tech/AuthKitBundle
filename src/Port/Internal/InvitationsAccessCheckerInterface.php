<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port\Internal;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0: AuthKit's internal view of the host port {@see
 * \Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface}: what the AuthKit services call, served by
 * an internal gate over the host's (or the built-in) implementation of that port. It replaces the public
 * `Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessCheckerInterface` (deprecated in 1.46.0, removed in 2.0).
 * Never implement it: implement the port.
 *
 * @internal
 */
interface InvitationsAccessCheckerInterface
{
    public function canManage(UserInterface $user, string $profile): bool;
}
