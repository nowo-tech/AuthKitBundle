<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

use Nowo\AuthKitBundle\Entity\Invitation;

/**
 * Status of an invitation, derived from its dates ({@see Invitation::status()}): no
 * scheduled job is needed for the expiry.
 */
enum InvitationStatus: string
{
    case Pending  = 'pending';
    case Expired  = 'expired';
    case Accepted = 'accepted';
    case Revoked  = 'revoked';

    /** Translation key of the status label (NowoAuthKitBundle domain). */
    public function labelKey(): string
    {
        return 'invitation.status.' . $this->value;
    }

    /** Resend and revoke apply to pending and expired invitations only. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Expired;
    }
}
