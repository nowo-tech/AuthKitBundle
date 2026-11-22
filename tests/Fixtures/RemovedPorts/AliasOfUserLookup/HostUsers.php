<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\RemovedPorts\AliasOfUserLookup;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * 2.0 W1-1 fixture (autodiscovered, loadable): a host user directory on the port, still published under the removed
 * UserLookupInterface id.
 */
#[AsAlias('Nowo\AuthKitBundle\User\UserLookupInterface')]
final class HostUsers implements UserDirectoryInterface
{
    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?AuthUserRef
    {
        return null;
    }

    public function findById(ProfileSettings $profile, string $id): ?AuthUserRef
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
