<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;

/**
 * Resolves users by the configured identifier field (exact match after NFC + lower-casing, see UserIdentifierMatcher) for magic login.
 */
final class MagicLoginUserResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProfileRegistry $profileRegistry,
        private readonly UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
    ) {
    }

    public function findByIdentifier(string $identifier, ?string $profileName = null): ?object
    {
        $profile = $this->resolveProfile($profileName);

        $repository = $this->entityManager->getRepository($profile->userClass);

        // The stored identifier must equal the input (NFC, case-insensitive): an accent/case-insensitive database
        // collation must not resolve a look-alike address to another account (SEC-004 H1).
        $user = $this->identifierMatcher->find($repository, $profile->userIdentifierField, $identifier);
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
