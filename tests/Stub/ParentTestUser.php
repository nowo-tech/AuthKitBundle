<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class ParentTestUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function getUserIdentifier(): string
    {
        return 'parent@example.com';
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    private string $password = '';

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
