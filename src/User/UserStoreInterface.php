<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Port (1.46.0): the framework-free twin of the deprecated {@see UserWriterInterface} (REQ-ARCH-001 rule 3). Stores the
 * users AuthKit creates or changes. The references AuthKit passes carry the user object it works on
 * ({@see AuthUserRef::user()}: the one your {@see UserCreatorInterface} or {@see UserDirectoryInterface} returned).
 *
 * Same contract as the deprecated port: throw {@see DuplicateUserException} for a taken unique value; `remove()` is
 * the compensation of a failed acceptance / social sign-in / `first_user_only` race; after `changePassword()` AuthKit
 * always calls `save()` and dispatches {@see CredentialsChangedEvent} itself.
 */
interface UserStoreInterface
{
    public function add(ProfileSettings $profile, AuthUserRef $user): void;

    public function remove(ProfileSettings $profile, AuthUserRef $user): void;

    /** Sets the new password hash (already hashed with the user's hasher). */
    public function changePassword(ProfileSettings $profile, AuthUserRef $user, string $hashedPassword): void;

    /** Stores the pending changes of an existing user. */
    public function save(ProfileSettings $profile, AuthUserRef $user): void;
}
