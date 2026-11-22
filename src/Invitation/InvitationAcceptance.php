<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
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

    /**
     * @param UserInterface|null $user not public since 2.0: read userRef()
     */
    private function __construct(
        public string $status,
        private ?UserInterface $user = null,
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

    /**
     * The account, framework-free (1.46.0); null unless accepted.
     */
    public function userRef(): ?AuthUserRef
    {
        return AuthUserRefs::fromNullableUser($this->user);
    }

    /**
     * The `$status` value (1.55.0): read it here; same value as the property.
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * The `$invitation` value (1.55.0): read it here; same value as the property.
     */
    public function invitation(): ?InvitationSnapshot
    {
        return $this->invitation;
    }

    /**
     * The `$refusal` value (1.55.0): read it here; same value as the property.
     */
    public function refusal(): ?InvitationRefusal
    {
        return $this->refusal;
    }
}
