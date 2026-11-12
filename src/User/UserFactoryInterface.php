<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Port (1.41): builds the host user for registration, invited registration, social sign-up and admin creation. It
 * does not store it: {@see UserWriterInterface::add()} does.
 *
 * Default: {@see PropertyAccessUserFactory} (no-argument constructor of `user_class`, PropertyAccessor writes).
 * Alias the interface to your own service to use a named constructor or a domain factory.
 *
 * @deprecated since 1.46.0, implement {@see UserCreatorInterface} (framework-free, REQ-ARCH-001 rule 3) instead; removed in 2.0.
 */
interface UserFactoryInterface
{
    public function create(ProfileSettings $profile, NewUser $newUser): UserInterface&PasswordAuthenticatedUserInterface;
}
