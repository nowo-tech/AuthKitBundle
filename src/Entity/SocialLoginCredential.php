<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;

/**
 * OAuth application credentials for a social provider (stored in the database).
 */
#[ORM\Entity(repositoryClass: SocialLoginCredentialRepository::class)]
#[ORM\Table(name: 'auth_kit_social_credential')]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_social_credential_provider', columns: ['provider'])]
class SocialLoginCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns via reflection)

    /** Provider key (google, github, microsoft, or custom slug). */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $provider = '';

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $label = '';

    #[ORM\Column(name: 'client_id', type: Types::STRING, length: 255)]
    private string $clientId = '';

    /** OAuth client secret (encrypted at rest when nowo-tech/doctrine-encrypt-bundle is configured). */
    #[ORM\Column(name: 'client_secret', type: Types::TEXT)]
    #[Encrypted]
    private string $clientSecret = '';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    /**
     * When true, this credential is shown as organization/SSO (OIDC) rather than consumer social login.
     */
    #[ORM\Column(name: 'enterprise_sso', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $enterpriseSso = false;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = [];

    #[ORM\Column(name: 'authorize_url', type: Types::STRING, length: 512, nullable: true)]
    private ?string $authorizeUrl = null;

    #[ORM\Column(name: 'token_url', type: Types::STRING, length: 512, nullable: true)]
    private ?string $tokenUrl = null;

    #[ORM\Column(name: 'userinfo_url', type: Types::STRING, length: 512, nullable: true)]
    private ?string $userinfoUrl = null;

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        $this->touch();

        return $this;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): self
    {
        $this->clientId = $clientId;
        $this->touch();

        return $this;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(string $clientSecret): self
    {
        $this->clientSecret = $clientSecret;
        $this->touch();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->touch();

        return $this;
    }

    public function isEnterpriseSso(): bool
    {
        return $this->enterpriseSso;
    }

    public function setEnterpriseSso(bool $enterpriseSso): self
    {
        $this->enterpriseSso = $enterpriseSso;
        $this->touch();

        return $this;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param array<int, string> $scopes */
    public function setScopes(array $scopes): self
    {
        $this->scopes = array_values($scopes);
        $this->touch();

        return $this;
    }

    public function getAuthorizeUrl(): ?string
    {
        return $this->authorizeUrl;
    }

    public function setAuthorizeUrl(?string $authorizeUrl): self
    {
        $this->authorizeUrl = $authorizeUrl;
        $this->touch();

        return $this;
    }

    public function getTokenUrl(): ?string
    {
        return $this->tokenUrl;
    }

    public function setTokenUrl(?string $tokenUrl): self
    {
        $this->tokenUrl = $tokenUrl;
        $this->touch();

        return $this;
    }

    public function getUserinfoUrl(): ?string
    {
        return $this->userinfoUrl;
    }

    public function setUserinfoUrl(?string $userinfoUrl): self
    {
        $this->userinfoUrl = $userinfoUrl;
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
