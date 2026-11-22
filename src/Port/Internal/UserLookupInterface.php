<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port\Internal;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0: AuthKit's internal view of the host port {@see UserDirectoryInterface}: what the
 * AuthKit services call, served by an internal gate over the host's (or the built-in) implementation of that port. It
 * replaces the public `Nowo\AuthKitBundle\User\UserLookupInterface` (deprecated in 1.46.0, removed in 2.0). Never
 * implement it: implement the port.
 *
 * @internal
 */
interface UserLookupInterface
{
    /** See {@see UserDirectoryInterface::findByIdentifier()}; the user object, fresh. */
    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface;

    /** The user with this id (as stored by AuthKit: `(string) getId()`), fresh. */
    public function findById(ProfileSettings $profile, string $id): ?UserInterface;

    /** Number of users of the profile (`first_user_only`). */
    public function countUsers(ProfileSettings $profile): int;

    /** See {@see UserDirectoryInterface::hasCaseVariant()}. */
    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool;
}
