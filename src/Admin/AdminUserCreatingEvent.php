<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Admin;

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

    public function getUser(): UserInterface&PasswordAuthenticatedUserInterface
    {
        return $this->user;
    }

    public function getProfileName(): string
    {
        return $this->profileName;
    }
}
