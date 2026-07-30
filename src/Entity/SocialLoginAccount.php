<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;

/**
 * Linked social identity for an application user (tokens + provider subject).
 */
#[ORM\Entity(repositoryClass: SocialLoginAccountRepository::class)]
#[ORM\Table(name: 'auth_kit_social_account')]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_social_account_provider_subject', columns: ['provider', 'provider_user_id'])]
class SocialLoginAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns via reflection)

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $provider = '';

    #[ORM\Column(name: 'provider_user_id', type: Types::STRING, length: 255)]
    private string $providerUserId = '';

    /** Application user class FQCN. */
    #[ORM\Column(name: 'user_class', type: Types::STRING, length: 255)]
    private string $userClass = '';

    /** Stringified primary key of the application user. */
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 64)]
    private string $userId = '';

    /** Security user identifier (typically email). */
    #[ORM\Column(name: 'user_identifier', type: Types::STRING, length: 255)]
    private string $userIdentifier = '';

    #[ORM\Column(name: 'access_token', type: Types::TEXT, nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(name: 'refresh_token', type: Types::TEXT, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(name: 'token_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'display_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $displayName = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'raw_profile', type: Types::JSON)]
    private array $rawProfile = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now             = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;
        $this->touch();

        return $this;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }

    public function setProviderUserId(string $providerUserId): self
    {
        $this->providerUserId = $providerUserId;
        $this->touch();

        return $this;
    }

    public function getUserClass(): string
    {
        return $this->userClass;
    }

    public function setUserClass(string $userClass): self
    {
        $this->userClass = $userClass;
        $this->touch();

        return $this;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;
        $this->touch();

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(string $userIdentifier): self
    {
        $this->userIdentifier = $userIdentifier;
        $this->touch();

        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): self
    {
        $this->accessToken = $accessToken;
        $this->touch();

        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;
        $this->touch();

        return $this;
    }

    public function getTokenExpiresAt(): ?DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?DateTimeImmutable $tokenExpiresAt): self
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
        $this->touch();

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        $this->touch();

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;
        $this->touch();

        return $this;
    }

    /** @return array<string, mixed> */
    public function getRawProfile(): array
    {
        return $this->rawProfile;
    }

    /** @param array<string, mixed> $rawProfile */
    public function setRawProfile(array $rawProfile): self
    {
        $this->rawProfile = $rawProfile;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
