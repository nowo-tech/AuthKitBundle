<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\UserStoreInterface;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Serves the deprecated {@see UserWriterInterface} alias with the host's {@see UserStoreInterface} (1.46.0, wired by
 * {@see AuthKitPortsPass}): each user object becomes an
 * {@see AuthUserRef} carrying it. Exceptions (a duplicate) propagate.
 *
 * @internal
 */
final readonly class UserStoreGate implements UserWriterInterface
{
    public function __construct(private UserStoreInterface $store)
    {
    }

    public function add(ProfileSettings $profile, UserInterface $user): void
    {
        $this->store->add($profile, AuthUserRefs::fromUser($user));
    }

    public function remove(ProfileSettings $profile, UserInterface $user): void
    {
        $this->store->remove($profile, AuthUserRefs::fromUser($user));
    }

    public function changePassword(ProfileSettings $profile, PasswordAuthenticatedUserInterface $user, string $hashedPassword): void
    {
        $this->store->changePassword($profile, AuthUserRefs::fromUser($user), $hashedPassword);
    }

    public function save(ProfileSettings $profile, PasswordAuthenticatedUserInterface|UserInterface $user): void
    {
        $this->store->save($profile, AuthUserRefs::fromUser($user));
    }
}
