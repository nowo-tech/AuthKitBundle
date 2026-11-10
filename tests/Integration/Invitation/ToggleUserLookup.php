<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decorates the user lookup; `hideByIdentifier` makes the social linker find no account (its failure branch).
 */
final class ToggleUserLookup implements UserLookupInterface
{
    public bool $hideByIdentifier = false;

    public function __construct(
        private readonly UserLookupInterface $inner,
    ) {
    }

    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        return $this->hideByIdentifier ? null : $this->inner->findByIdentifier($profile, $identifier);
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
