<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\UserCreatorInterface;
use Nowo\AuthKitBundle\User\UserFactoryInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Serves the deprecated {@see UserFactoryInterface} alias with the host's {@see UserCreatorInterface} (1.46.0, wired by
 * {@see AuthKitPortsPass}). The returned reference must carry a
 * password-authenticated Symfony security user (`LogicException` otherwise).
 *
 * @internal
 */
final readonly class UserCreatorGate implements UserFactoryInterface
{
    public function __construct(private UserCreatorInterface $creator)
    {
    }

    public function create(ProfileSettings $profile, NewUser $newUser): UserInterface&PasswordAuthenticatedUserInterface
    {
        return AuthUserRefs::passwordUser($this->creator->create($profile, $newUser), UserCreatorInterface::class . '::create()');
    }
}
