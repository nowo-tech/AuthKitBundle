<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/** A host directory that knows nobody (build-pass fixtures). */
class UserDirectoryHost implements UserDirectoryInterface
{
    public static function create(): self
    {
        return new self();
    }

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
