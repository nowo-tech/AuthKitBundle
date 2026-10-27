<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeImmutable;

/**
 * Context passed to notifiers so apps can email the magic login URL.
 *
 * Security: send the link ONLY to `identifier`, the identifier STORED on the account (since 1.31; before it was the
 * address typed in the request form), or to an address read from `user`. Never to request input.
 */
final readonly class MagicLoginNotificationContext
{
    public function __construct(
        public string $identifier,
        public string $loginUrl,
        public DateTimeImmutable $expiresAt,
        public ?string $maskedIdentifier = null,
        /** Recipient's preferred locale (allowed by locale.enabled), or null to use the request locale. */
        public ?string $recipientLocale = null,
        /** The resolved account (since 1.31). */
        public ?object $user = null,
    ) {
    }
}
