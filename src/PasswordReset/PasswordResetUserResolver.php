<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;

/**
 * Resolves users by the configured identifier field.
 */
final class PasswordResetUserResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProfileRegistry $profileRegistry,
    ) {
    }

    public function findByIdentifier(string $identifier, ?string $profileName = null): ?object
    {
        $profile = $this->resolveProfile($profileName);

        $repository = $this->entityManager->getRepository($profile->userClass);

        return $repository->findOneBy([$profile->userIdentifierField => $identifier]);
    }

    private function resolveProfile(?string $profileName): ProfileSettings
    {
        return $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }
}
