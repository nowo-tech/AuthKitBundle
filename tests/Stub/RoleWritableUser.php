<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User entity without setRoles(); roles are assigned via PropertyAccessor.
 */
final class RoleWritableUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public string $email = '';

    public string $password = '';

    /** @var list<string> */
    public array $roles = [];

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anonymous@example.com';
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }
}
