<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub;

use Nowo\AuthKitBundle\Locale\PreferredLocaleAwareInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class PreferredLocaleTestUser implements UserInterface, PasswordAuthenticatedUserInterface, PreferredLocaleAwareInterface
{
    public function __construct(
        private readonly string $email = 'user@example.com',
        private readonly ?string $preferredLocale = null,
    ) {
    }

    public function getPreferredLocale(): ?string
    {
        return $this->preferredLocale;
    }

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anonymous@example.com';
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return '';
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
