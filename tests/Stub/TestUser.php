<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class TestUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    private string $email = '';

    private string $password = '';

    private ?string $passwordResetToken = null;

    private ?DateTimeImmutable $passwordResetExpiresAt = null;

    /** @var list<string> */
    private array $roles = [];

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anonymous@example.com';
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): void
    {
        $this->passwordResetToken = $passwordResetToken;
    }

    public function getPasswordResetExpiresAt(): ?DateTimeImmutable
    {
        return $this->passwordResetExpiresAt;
    }

    public function setPasswordResetExpiresAt(?DateTimeImmutable $passwordResetExpiresAt): void
    {
        $this->passwordResetExpiresAt = $passwordResetExpiresAt;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function eraseCredentials(): void
    {
    }
}
