<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Entity\Invitation;

use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_contains;

/**
 * Whose invitation data is erased or exported (1.45, GDPR ports). Invitations name people two ways, and each subject is
 * one of them:
 *
 * - {@see self::fromEmail()}: the address an invitation was sent TO. Normalised exactly as the bundle stores it
 *   ({@see Invitation::normalizeEmail()}: trimmed, lower-cased); nothing else is folded (accents stay).
 * - {@see self::fromUserIdentifier()}: the security identifier of an actor (`invited_by`, `last_sent_by`, `revoked_by`,
 *   `accepted_by`). Kept as given (no trimming, no case folding); cut to {@see Invitation::IDENTIFIER_MAX_LENGTH}
 *   characters, as the entity does when it stores one.
 *
 * Empty values and values with a NUL byte are rejected.
 */
final readonly class InvitationPersonalDataSubject
{
    public const KIND_EMAIL = 'email';

    public const KIND_USER_IDENTIFIER = 'user_identifier';

    /**
     * @param self::KIND_* $kind
     */
    private function __construct(
        public string $kind,
        public string $value,
    ) {
    }

    /** The invitee's address (invitations sent to it). */
    public static function fromEmail(string $email): self
    {
        $normalized = Invitation::normalizeEmail($email);
        self::assertUsable($normalized, 'e-mail');
        if (mb_strlen($normalized) > Invitation::EMAIL_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('An invitation e-mail has at most %d characters.', Invitation::EMAIL_MAX_LENGTH));
        }

        return new self(self::KIND_EMAIL, $normalized);
    }

    /** An actor's security identifier (invitations it issued, resent, revoked or accepted). */
    public static function fromUserIdentifier(string $identifier): self
    {
        self::assertUsable($identifier, 'user identifier');

        return new self(self::KIND_USER_IDENTIFIER, mb_substr($identifier, 0, Invitation::IDENTIFIER_MAX_LENGTH));
    }

    public function isEmail(): bool
    {
        return $this->kind === self::KIND_EMAIL;
    }

    /** Kind and value, for de-duplication. */
    public function key(): string
    {
        return $this->kind . "\0" . $this->value;
    }

    private static function assertUsable(string $value, string $what): void
    {
        if ($value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf('An invitation personal-data subject needs a non-empty %s without NUL bytes.', $what));
        }
    }
}
