<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\Enum\PasswordResetMode;

/**
 * Checks whether password reset routes and flows are active.
 */
final class PasswordResetGate
{
    public function __construct(
        private readonly string $passwordResetMode,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->passwordResetMode === PasswordResetMode::Enabled->value;
    }
}
