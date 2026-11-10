<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;

/**
 * Pre-check when an invitation link is opened (1.42): dispatched by {@see InvitationAcceptor::precheck()} before the
 * accept page shows its form for a pending invitation (GET), and before a social acceptance starts. A listener may
 * {@see self::refuse()} it with a translatable reason (e.g. "the organization has no seat left"): the page shows that
 * reason instead of the form. Nothing is consumed: the invitation stays pending and the link keeps working, so the
 * invitee can open it again once the host allows it. {@see InvitationAcceptingEvent} still runs on submit.
 *
 * Only reached with the current token of a pending invitation (an unknown, used, revoked or replaced link shows the
 * neutral page without dispatching anything; an expired one shows "expired"), so the reason is shown only to the link
 * holder. Like the pre-accept hook it can only refuse; a throwing listener refuses.
 */
final class InvitationOpeningEvent
{
    private ?InvitationRefusal $refusal = null;

    private bool $refused = false;

    public function __construct(
        public readonly InvitationSnapshot $invitation,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /** Refuses the opening; the first reason given is kept (null: the neutral "cannot be accepted now" text). */
    public function refuse(?InvitationRefusal $reason = null): void
    {
        if (!$this->refused) {
            // @igor-ignore - Event object of one dispatch, not a shared service.
            $this->refusal = $reason;
        }
        // @igor-ignore - Event object of one dispatch, not a shared service.
        $this->refused = true;
    }

    public function isRefused(): bool
    {
        return $this->refused;
    }

    public function getRefusal(): ?InvitationRefusal
    {
        return $this->refusal;
    }
}
