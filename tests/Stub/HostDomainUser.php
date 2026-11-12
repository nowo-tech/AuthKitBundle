<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub;

use Deprecated;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A host security user that is not a Doctrine entity and has no public setters or no-argument constructor (1.41
 * user ports): built by a named constructor, its password changed by a method.
 */
final class HostDomainUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        private readonly string $uuid,
        private readonly string $email,
        private string $passwordHash,
        private readonly array $roles,
    ) {
    }

    /**
     * @param list<string> $roles
     */
    public static function register(string $uuid, string $email, string $passwordHash, array $roles): self
    {
        return new self($uuid, $email, $passwordHash, $roles);
    }

    public function getId(): string
    {
        return $this->uuid;
    }

    public function changePassword(string $hash): void
    {
        $this->passwordHash = $hash;
    }

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anonymous';
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    #[Deprecated]
    public function eraseCredentials(): void
    {
    }
}
