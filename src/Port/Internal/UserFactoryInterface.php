<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port\Internal;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\UserCreatorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0: AuthKit's internal view of the host port {@see UserCreatorInterface}: what the AuthKit
 * services call, served by an internal gate over the host's (or the built-in) implementation of that port. It replaces
 * the public `Nowo\AuthKitBundle\User\UserFactoryInterface` (deprecated in 1.46.0, removed in 2.0). Never implement it:
 * implement the port.
 *
 * @internal
 */
interface UserFactoryInterface
{
    public function create(ProfileSettings $profile, NewUser $newUser): UserInterface&PasswordAuthenticatedUserInterface;
}
