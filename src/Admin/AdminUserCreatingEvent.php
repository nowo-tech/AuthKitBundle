<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Admin;

use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched by {@see AdminUserCreator} before the new admin is persisted.
 *
 * Listeners may fill host-specific required fields (display name, timestamps, …) on the user.
 */
final class AdminUserCreatingEvent
{
    public function __construct(
        private readonly UserInterface&PasswordAuthenticatedUserInterface $user,
        private readonly string $profileName,
    ) {
    }

    /**
     * @deprecated since 1.46.0, use userRef(); removed in 2.0
     */
    public function getUser(): UserInterface&PasswordAuthenticatedUserInterface
    {
        return $this->user;
    }

    public function getProfileName(): string
    {
        return $this->profileName;
    }

    /**
     * The user, framework-free (1.46.0, REQ-ARCH-001 rule 3).
     */
    public function userRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->user);
    }
}
