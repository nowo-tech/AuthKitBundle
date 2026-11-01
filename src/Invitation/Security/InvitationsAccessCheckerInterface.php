<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * REQ-UI-002 access checker of the invitation administration pages (`invitations.security.access_checker`). Called
 * on every administration request (list, invite, resend, revoke) with the signed-in user and the profile.
 */
interface InvitationsAccessCheckerInterface
{
    public function canManage(UserInterface $user, string $profile): bool;
}
