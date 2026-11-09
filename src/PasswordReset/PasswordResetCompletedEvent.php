<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

/**
 * A password was replaced through the reset link or code (1.41). Dispatched after the new hash is stored (after the
 * flush), with scalar ids only, so an application listener can raise its own `PasswordChanged` domain event.
 */
final readonly class PasswordResetCompletedEvent
{
    /**
     * @param class-string $userClass
     */
    public function __construct(
        public string $profile,
        public string $userClass,
        public ?string $userId,
        public string $userIdentifier,
    ) {
    }
}
