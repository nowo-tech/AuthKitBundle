<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User\Event;

use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserIds;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;

/**
 * A user was created by AuthKit (1.41): registration page, accepted invitation, first social sign-in or admin
 * creation. Dispatched once the account is stored (after the flush, and after the commit of the invitation / social
 * transaction), with scalar ids only, so an application listener can raise its own domain event or enqueue a message
 * without touching the bundle's objects.
 *
 * Not dispatched when the account is rolled back (`first_user_only` race, failed invitation or social sign-in).
 * {@see UserRegistrar::registerInvited()} called directly does not dispatch it: the
 * invitation acceptor does, after its commit.
 */
final readonly class UserRegisteredEvent
{
    /**
     * @param class-string $userClass
     * @param list<string> $roles
     */
    public function __construct(
        public string $profile,
        public string $userClass,
        public ?string $userId,
        public string $userIdentifier,
        public UserCreationSource $source,
        public array $roles = [],
    ) {
    }

    /** The event of a user just stored; `$roles` = `getRoles()` of the stored user. */
    public static function of(string $profile, UserInterface $user, UserCreationSource $source): self
    {
        return new self($profile, $user::class, UserIds::of($user), $user->getUserIdentifier(), $source, array_values($user->getRoles()));
    }
}
