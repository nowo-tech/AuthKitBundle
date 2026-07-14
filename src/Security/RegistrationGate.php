<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;

/**
 * Determines whether registration is currently allowed.
 */
final class RegistrationGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProfileRegistry $profileRegistry,
    ) {
    }

    public function isRegistrationAllowed(?string $profileName = null): bool
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        $registrationMode = RegistrationMode::from($profile->registrationMode);

        return match ($registrationMode) {
            RegistrationMode::Disabled      => false,
            RegistrationMode::Always        => true,
            RegistrationMode::FirstUserOnly => $this->countUsers($profile->userClass) === 0,
        };
    }

    /**
     * @param class-string $userClass
     */
    private function countUsers(string $userClass): int
    {
        return $this->entityManager
            ->getRepository($userClass)
            ->count([]);
    }
}
