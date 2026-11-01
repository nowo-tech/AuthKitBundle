<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use DateTimeImmutable;

/**
 * Result of inviting or resending: e-mailed, the link to show once (no delivery and
 * `invitations.show_link_when_undelivered`), not delivered, or refused (rate limited, no longer open).
 */
final readonly class InvitationOutcome
{
    public const SENT = 'sent';

    public const LINK = 'link';

    public const NOT_DELIVERED = 'not_delivered';

    public const RATE_LIMITED = 'rate_limited';

    public const NOT_OPEN = 'not_open';

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

    public static function refused(string $status, ?InvitationSnapshot $invitation = null, ?DateTimeImmutable $retryAfter = null): self
    {
        return new self($status, $invitation, null, $retryAfter);
    }
}
