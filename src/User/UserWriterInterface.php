<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Port (1.41): stores the host users AuthKit creates or changes. Default: {@see Doctrine\DoctrineUserWriter} (persist +
 * flush on the bundle's entity manager). Alias the interface to your repository adapter when the security user is not
 * a Doctrine entity, or when your domain owns user persistence.
 */
interface UserWriterInterface
{
    /**
     * Stores a user built by {@see UserFactoryInterface}. When a unique value (identifier, e-mail) is already taken,
     * throw {@see DuplicateUserException}; the default lets Doctrine's `UniqueConstraintViolationException` through,
     * which AuthKit treats the same.
     */
    public function add(ProfileSettings $profile, UserInterface $user): void;

    /** Removes a user just added (the `first_user_only` race rollback). */
    public function remove(ProfileSettings $profile, UserInterface $user): void;

    /**
     * Sets the new password hash (already hashed with the user's hasher). The default only writes the property: the
     * caller's next {@see self::save()} (or the bundle's own flush) stores it. Other implementations may store it at once.
     */
    public function changePassword(ProfileSettings $profile, PasswordAuthenticatedUserInterface $user, string $hashedPassword): void;

    /** Stores the pending changes of an existing user (password reset completion). */
    public function save(ProfileSettings $profile, PasswordAuthenticatedUserInterface|UserInterface $user): void;
}
