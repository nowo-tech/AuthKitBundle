<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Host SPI (1.46.0; autoconfigured, tag `nowo_auth_kit.invitation_grant`), framework-free (REQ-ARCH-001 rule 3).
 * Applies what an accepted invitation carries besides the roles, inside the acceptance transaction after the account is
 * persisted and the invitation claimed; throw {@see InvitationRefusedException} to refuse the acceptance.
 *
 * The tag is a constant of this interface (`InvitationGrantHandlerInterface::TAG`; declared in
 * {@see InvitationGrantTag} since 1.56.0). 2.0: the only interface of this SPI (the 1.32 `InvitationGrantInterface` is
 * removed).
 */
interface InvitationGrantHandlerInterface extends InvitationGrantTag
{
    public function grantTo(InvitationSnapshot $invitation, AuthUserRef $user): void;
}
