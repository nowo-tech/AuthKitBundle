<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Identity;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Outermost decorator of the user lookup port (1.48.0): every lookup by identifier, whoever serves the port (the
 * built-in Doctrine lookup, a host {@see UserLookupInterface} or a host {@see UserDirectoryInterface}), receives the
 * identifier normalised by {@see UserIdentifierNormalizerInterface}; a refused identifier finds no user and reaches no
 * query. The other methods are delegated unchanged. Stateless (worker safe).
 *
 * @internal
 */
final readonly class NormalizingUserLookup implements UserLookupInterface
{
    public function __construct(
        private UserLookupInterface $inner,
        private UserIdentifierNormalizerInterface $normalizer,
    ) {
    }

    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        $normalized = $identifier === '' ? null : $this->normalizer->normalize($identifier);

        return $normalized === null ? null : $this->inner->findByIdentifier($profile, $normalized);
    }

    public function findById(ProfileSettings $profile, string $id): ?UserInterface
    {
        return $this->inner->findById($profile, $id);
    }

    public function countUsers(ProfileSettings $profile): int
    {
        return $this->inner->countUsers($profile);
    }

    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool
    {
        return $this->inner->hasCaseVariant($profile, $property, $value);
    }
}
