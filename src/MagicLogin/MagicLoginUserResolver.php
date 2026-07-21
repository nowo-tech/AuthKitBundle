<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;

/**
 * Resolves users by the configured identifier field for magic login.
 */
final class MagicLoginUserResolver
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
