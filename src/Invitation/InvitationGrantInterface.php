<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host SPI (autoconfigured, tag `nowo_auth_kit.invitation_grant`): applies what an accepted invitation carries
 * besides the roles (memberships, groups, a seat…) from {@see InvitationSnapshot::$payload}. Called inside the
 * acceptance transaction after the account is persisted and the invitation claimed; throw
 * {@see InvitationRefusedException} to refuse the acceptance (everything is rolled back, the invitee sees the
 * neutral "could not be accepted" page). Re-check here anything the inviter may no longer grant.
 *
 * @deprecated since 1.46.0, implement {@see InvitationGrantHandlerInterface} (framework-free, REQ-ARCH-001 rule 3) instead; removed in 2.0.
 */
interface InvitationGrantInterface
{
    public const TAG = 'nowo_auth_kit.invitation_grant';

    public function grant(InvitationSnapshot $invitation, UserInterface $user): void;
}
