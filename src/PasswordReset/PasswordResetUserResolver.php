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

        $user = $repository->findOneBy([$profile->userIdentifierField => $identifier]);
        if ($user === null) {
            return null;
        }

        // Long-lived workers keep the identity map: refresh so bans/identifier changes apply.
        if ($this->entityManager->contains($user)) {
            $this->entityManager->refresh($user);
        }

        return $user;
    }

    private function resolveProfile(?string $profileName): ProfileSettings
    {
        return $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }
}
