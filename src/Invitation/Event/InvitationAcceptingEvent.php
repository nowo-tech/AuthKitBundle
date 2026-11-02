<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;

/**
 * Pre-accept hook (1.33), dispatched before the acceptance transaction of a pending invitation (form or social).
 * A listener may {@see self::refuse()} it with a translatable reason shown to the invitee (e.g. "no seat left"):
 * nothing is created, the invitation stays pending. Listeners cannot grant anything: the acceptance still needs a
 * pending invitation, the current token and the `invitations.roles` re-check, and a grant may still refuse it.
 */
final class InvitationAcceptingEvent
{
    private ?InvitationRefusal $refusal = null;

    private bool $refused = false;

    public function __construct(
        public readonly InvitationSnapshot $invitation,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /** Refuses the acceptance; the first reason given is kept (null: the neutral "could not be accepted" text). */
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
