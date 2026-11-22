<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Access rule of the invitation administration pages (1.46.0, `invitations.security.access_checker`): the
 * framework-free port (REQ-ARCH-001 rule 3; 2.0: replaces the removed `InvitationsAccessCheckerInterface`). Called on
 * every administration request (list, invite, resend, revoke) with the signed-in user and the profile.
 */
interface InvitationsAccessPolicyInterface
{
    public function allowsManage(AuthUserRef $user, string $profile): bool;
}
