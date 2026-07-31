<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\Enum\QrLoginMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;

/**
 * Checks whether QR phone login is active for a profile.
 */
final class QrLoginGate
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

        return $profile->qrLogin['mode'] === QrLoginMode::Enabled->value;
    }
}
