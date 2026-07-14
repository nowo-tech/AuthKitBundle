<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\Enum\PasswordResetMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;

/**
 * Checks whether password reset routes and flows are active.
 */
final class PasswordResetGate
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
    ) {
    }

    public function isEnabled(?string $profileName = null): bool
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        return $profile->passwordReset['mode'] === PasswordResetMode::Enabled->value;
    }
}
