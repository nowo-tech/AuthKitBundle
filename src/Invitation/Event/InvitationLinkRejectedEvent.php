<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;

/**
 * Dispatched (1.33) when an expired or revoked invitation link is opened on the accept page, e.g. to tell the inviter
 * the invitee tried a dead link. Only for a link whose token is still the invitation's current one; an unknown,
 * replaced or used link dispatches nothing. Neither the token, its hash nor the link is part of the event.
 */
final class InvitationLinkRejectedEvent
{
    public const REASON_EXPIRED = 'expired';

    public const REASON_REVOKED = 'revoked';

    public function __construct(
        public readonly InvitationSnapshot $invitation,
        public readonly string $reason,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    public function invitationId(): string
    {
        return $this->invitation->publicId;
    }

    public function profile(): string
    {
        return $this->invitation->profile;
    }
}
