<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use Nowo\AuthKitBundle\Enum\MagicLoginMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;

/**
 * Checks whether passwordless magic-login flows are active.
 */
final class MagicLoginGate
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

        return $profile->magicLogin['mode'] === MagicLoginMode::Enabled->value;
    }
}
