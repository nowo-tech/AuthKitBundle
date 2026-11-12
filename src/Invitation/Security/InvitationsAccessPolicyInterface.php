<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Access rule of the invitation administration pages (1.46.0, `invitations.security.access_checker`): the
 * framework-free twin of the deprecated {@see InvitationsAccessCheckerInterface} (REQ-ARCH-001 rule 3). Called on
 * every administration request (list, invite, resend, revoke) with the signed-in user and the profile. A service
 * implementing both interfaces is asked through the deprecated one until 2.0.
 */
interface InvitationsAccessPolicyInterface
{
    public function allowsManage(AuthUserRef $user, string $profile): bool;
}
