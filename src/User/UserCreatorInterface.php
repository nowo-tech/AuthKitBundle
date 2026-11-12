<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Port (1.46.0): the framework-free twin of the deprecated {@see UserFactoryInterface} (REQ-ARCH-001 rule 3). Builds
 * the user for registration, invited registration, social sign-up and admin creation; does not store it
 * ({@see UserStoreInterface::add()} does).
 *
 * The reference must carry the new security user object ({@see AuthUserRef::of()}), which must implement Symfony's
 * `PasswordAuthenticatedUserInterface`: AuthKit hashes its password and signs it in. Otherwise a `LogicException`.
 */
interface UserCreatorInterface
{
    public function create(ProfileSettings $profile, NewUser $newUser): AuthUserRef;
}
