<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves users by the configured identifier field for password reset through {@see UserLookupInterface} (1.41; default: the
 * Doctrine repository, exact match after NFC + lower-casing, see UserIdentifierMatcher).
 */
final class PasswordResetUserResolver
{
    private readonly UserLookupInterface $userLookup;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly ProfileRegistry $profileRegistry,
        UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
        ?UserLookupInterface $userLookup = null,
    ) {
        $this->userLookup = $userLookup ?? new DoctrineUserLookup($entityManager, $identifierMatcher);
    }

    public function findByIdentifier(string $identifier, ?string $profileName = null): ?UserInterface
    {
        return $this->userLookup->findByIdentifier($this->resolveProfile($profileName), $identifier);
    }

    private function resolveProfile(?string $profileName): ProfileSettings
    {
        return $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }
}
