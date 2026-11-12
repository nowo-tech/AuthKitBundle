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
 *
 * @deprecated since 1.46.0, implement {@see UserStoreInterface} (framework-free, REQ-ARCH-001 rule 3) instead; removed in 2.0.
 */
interface UserWriterInterface
{
    /**
     * Stores a user built by {@see UserFactoryInterface}. When a unique value (identifier, e-mail) is already taken,
     * throw {@see DuplicateUserException}; the default lets Doctrine's `UniqueConstraintViolationException` through,
     * which AuthKit treats the same.
     */
    public function add(ProfileSettings $profile, UserInterface $user): void;

    /**
     * Removes a user just added: the `first_user_only` race rollback, and the compensation of a failed invitation
     * acceptance or social sign-in when this writer stores outside the bundle's transaction (the Doctrine default is
     * rolled back with it and is not called).
     */
    public function remove(ProfileSettings $profile, UserInterface $user): void;

    /**
     * Sets the new password hash (already hashed with the user's hasher). AuthKit always calls {@see self::save()}
     * afterwards, so an implementation may defer storing to it. With any writer other than the Doctrine default, AuthKit
     * then dispatches {@see Event\CredentialsChangedEvent} itself (password reset completion, social
     * `link_existing_account: reset_password`): do not dispatch it again for those changes.
     */
    public function changePassword(ProfileSettings $profile, PasswordAuthenticatedUserInterface $user, string $hashedPassword): void;

    /** Stores the pending changes of an existing user (password reset completion). */
    public function save(ProfileSettings $profile, PasswordAuthenticatedUserInterface|UserInterface $user): void;
}
