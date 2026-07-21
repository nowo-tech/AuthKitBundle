<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeImmutable;

/**
 * Context passed to notifiers so apps can email the magic login URL.
 */
final readonly class MagicLoginNotificationContext
{
    public function __construct(
        public string $identifier,
        public string $loginUrl,
        public DateTimeImmutable $expiresAt,
        public ?string $maskedIdentifier = null,
    ) {
    }
}
