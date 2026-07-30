<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Enum\SocialLoginMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;

/**
 * Social login is active when the profile mode is enabled and at least one
 * provider credential exists (and is enabled) in the database.
 */
final class SocialLoginGate
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly SocialLoginCredentialRepository $credentialRepository,
    ) {
    }

    public function isEnabled(?string $profileName = null): bool
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        if ($profile->socialLogin['mode'] !== SocialLoginMode::Enabled->value) {
            return false;
        }

        return $this->credentialRepository->findEnabledOrdered() !== [];
    }
}
