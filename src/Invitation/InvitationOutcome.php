<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use DateTimeImmutable;

/**
 * Result of inviting or resending: e-mailed, the link to show once (no delivery and
 * `invitations.show_link_when_undelivered`), not delivered, revoked, or refused (rate limited, no longer open, roles the
 * actor does not hold, registration closed, `base_uri` empty).
 */
final readonly class InvitationOutcome
{
    public const SENT = 'sent';

    public const LINK = 'link';

    public const NOT_DELIVERED = 'not_delivered';

    public const RATE_LIMITED = 'rate_limited';

    public const NOT_OPEN = 'not_open';

    /** The actor does not hold every role of the invitation (SEC-004 CD M1). */
    public const NOT_ALLOWED = 'not_allowed';

    /** `registration_mode: disabled`: a link could not be accepted, so none is sent. */
    public const CLOSED = 'closed';

    /** `absolute_url.base_uri` resolved empty at runtime: no link is built from the request Host. */
    public const NOT_CONFIGURED = 'not_configured';

    public const REVOKED = 'revoked';

    /**
     * @param string|null $url the accept link, only for {@see self::LINK} (shown once, never logged)
     */
    private function __construct(
        public string $status,
        public ?InvitationSnapshot $invitation = null,
        public ?string $url = null,
        public ?DateTimeImmutable $retryAfter = null,
    ) {
    }

    public static function sent(InvitationSnapshot $invitation): self
    {
        return new self(self::SENT, $invitation);
    }

    public static function link(InvitationSnapshot $invitation, string $url): self
    {
        return new self(self::LINK, $invitation, $url);
    }

    public static function notDelivered(InvitationSnapshot $invitation): self
    {
        return new self(self::NOT_DELIVERED, $invitation);
    }

    public static function revoked(InvitationSnapshot $invitation): self
    {
        return new self(self::REVOKED, $invitation);
    }

    public static function refused(string $status, ?InvitationSnapshot $invitation = null, ?DateTimeImmutable $retryAfter = null): self
    {
        return new self($status, $invitation, null, $retryAfter);
    }
}
