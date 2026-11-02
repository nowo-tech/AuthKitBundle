<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Result of {@see InvitationAcceptor::attempt()} and {@see InvitationAcceptor::acceptPendingForSocial()} (1.33): the
 * account created, or why none was. Only {@see self::$refusal} (set by the host) is meant for the invitee.
 */
final readonly class InvitationAcceptance
{
    public const ACCEPTED = 'accepted';

    /** No pending invitation (no link opened in this session, unknown, used, revoked or replaced). */
    public const NO_INVITATION = 'no_invitation';

    public const EXPIRED = 'expired';

    /** Social acceptance: the provider did not assert a verified e-mail equal to the invited one. */
    public const EMAIL_MISMATCH = 'email_mismatch';

    /** Refused by the host (pre-accept event or grant); {@see self::$refusal} may carry the reason to show. */
    public const REFUSED = 'refused';

    /** Not accepted (claimed meanwhile, the e-mail already has an account, …). */
    public const NOT_ACCEPTED = 'not_accepted';

    private function __construct(
        public string $status,
        public ?UserInterface $user = null,
        public ?InvitationSnapshot $invitation = null,
        public ?InvitationRefusal $refusal = null,
    ) {
    }

    public static function accepted(UserInterface $user, InvitationSnapshot $invitation): self
    {
        return new self(self::ACCEPTED, $user, $invitation);
    }

    public static function failed(string $status, ?InvitationSnapshot $invitation = null, ?InvitationRefusal $refusal = null): self
    {
        return new self($status, null, $invitation, $refusal);
    }

    public function isAccepted(): bool
    {
        return $this->user instanceof UserInterface;
    }
}
