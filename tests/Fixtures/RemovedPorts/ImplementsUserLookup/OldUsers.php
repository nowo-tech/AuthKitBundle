<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\RemovedPorts\ImplementsUserLookup;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered): a host user lookup that still implements only the removed interface; the class
 * cannot be loaded.
 */
final class OldUsers implements UserLookupInterface
{
    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
    {
        return null;
    }

    public function findById(ProfileSettings $profile, string $id): ?UserInterface
    {
        return null;
    }

    public function countUsers(ProfileSettings $profile): int
    {
        return 0;
    }

    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool
    {
        return false;
    }
}
