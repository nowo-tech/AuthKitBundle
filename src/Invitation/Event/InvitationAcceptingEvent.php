<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Pre-accept hook (1.33), dispatched before the acceptance transaction of a pending invitation (form, social or, 1.42,
 * an existing account through {@see InvitationAcceptor::acceptExisting()}). A listener may {@see self::refuse()} it with a
 * translatable reason shown to the invitee (e.g. "no seat left"): nothing is created, the invitation stays pending.
 * Listeners cannot grant anything: the acceptance still needs a pending invitation, the current token and the
 * `invitations.roles` re-check, and a grant may still refuse it.
 *
 * 1.42: {@see self::$user} is the existing account accepting it (null: a new account is about to be created), so a
 * listener that refuses an address that already has an account can let that account's own acceptance through.
 */
final class InvitationAcceptingEvent
{
    private ?InvitationRefusal $refusal = null;

    private bool $refused = false;

    public function __construct(
        public readonly InvitationSnapshot $invitation,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?UserInterface $user = null,
    ) {
    }

    /** Whether an existing account accepts it (1.42, {@see InvitationAcceptor::acceptExisting()}). */
    public function isExistingAccount(): bool
    {
        return $this->user instanceof UserInterface;
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
