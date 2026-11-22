<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Host SPI (1.46.0; autoconfigured, tag `nowo_auth_kit.invitation_grant`), framework-free (REQ-ARCH-001 rule 3).
 * Applies what an accepted invitation carries besides the roles, inside the acceptance transaction after the account is
 * persisted and the invitation claimed; throw {@see InvitationRefusedException} to refuse the acceptance.
 *
 * 2.0: the only interface of this SPI (the 1.32 `InvitationGrantInterface` is removed); the tag moved here from it.
 */
interface InvitationGrantHandlerInterface
{
    public const TAG = 'nowo_auth_kit.invitation_grant';

    public function grantTo(InvitationSnapshot $invitation, AuthUserRef $user): void;
}
