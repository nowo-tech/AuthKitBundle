<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Port\Internal\UserFactoryInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\UserCreatorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * AuthKit's internal {@see UserFactoryInterface} over the {@see UserCreatorInterface} port (1.46.0; 2.0: always, see
 * services.yaml and {@see AuthKitPortsPass}). The returned reference must carry a password-authenticated Symfony
 * security user (`LogicException` otherwise).
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
