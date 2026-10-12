<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User entity whose roles are fixed (no setter, not writable).
 */
final class NoRolesTestUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public string $username = '';

    public string $secret = '';

    public function getUserIdentifier(): string
    {
        return $this->username !== '' ? $this->username : 'anonymous';
    }

    public function getPassword(): string
    {
        return $this->secret;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
