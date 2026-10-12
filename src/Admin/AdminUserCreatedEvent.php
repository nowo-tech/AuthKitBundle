<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Admin;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched by {@see AdminUserCreator} after the new admin has been flushed.
 *
 * Hosts hook here for follow-up work, e.g. marking an installation wizard as completed.
 */
final class AdminUserCreatedEvent
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly UserInterface&PasswordAuthenticatedUserInterface $user,
        private readonly string $profileName,
        private readonly array $roles,
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

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }
}
