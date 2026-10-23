<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeImmutable;

/**
 * Result of {@see PasswordResetRequestHandler::handle()}. Only "throttled" is exposed: whether the identifier matched a
 * user (or the client hit the request rate limit) stays hidden, so the request page cannot enumerate accounts.
 */
final readonly class PasswordResetRequestOutcome
{
    public function __construct(
        public bool $throttled = false,
        public ?DateTimeImmutable $availableAt = null,
    ) {
    }

    public static function accepted(): self
    {
        return new self();
    }

    public static function throttled(PasswordResetThrottledException $exception): self
    {
        return new self(true, $exception->getAvailableAt());
    }
}
