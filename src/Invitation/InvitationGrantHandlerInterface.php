<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Host SPI (1.46.0; autoconfigured, tag `nowo_auth_kit.invitation_grant`): the framework-free twin of the deprecated
 * {@see InvitationGrantInterface} (REQ-ARCH-001 rule 3). Applies what an accepted invitation carries besides the roles,
 * inside the acceptance transaction after the account is persisted and the invitation claimed; throw
 * {@see InvitationRefusedException} to refuse the acceptance. A service implementing both interfaces is asked through
 * the deprecated one until 2.0.
 */
interface InvitationGrantHandlerInterface
{
    public function grantTo(InvitationSnapshot $invitation, AuthUserRef $user): void;
}
