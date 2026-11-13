<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;

use function in_array;
use function is_bool;
use function is_scalar;
use function sprintf;
use function strtolower;
use function trim;

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

    /**
     * Extra parameters merged into the authorization request of this provider (1.48), e.g.
     * `['prompt' => 'select_account', 'access_type' => 'offline']`. A null value removes a parameter the bundle
     * would otherwise send for a built-in provider. The protocol parameters the bundle owns
     * ({@see OAuth2Client::RESERVED_AUTHORIZATION_PARAMS}) are refused here and ignored when read.
     *
     * @var array<string, string|null>
     */
    #[ORM\Column(name: 'authorization_params', type: Types::JSON)]
    private array $authorizationParams = [];

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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->scopes = array_values($scopes);
        $this->touch();

        return $this;
    }

    /**
     * @return array<string, string|null>
     */
    public function getAuthorizationParams(): array
    {
        return $this->authorizationParams;
    }

    /**
     * @param array<string, mixed> $params parameter name => value; null removes a parameter the bundle would send by
     *                                     default for this provider
     *
     * @throws InvalidArgumentException for an empty name, a non-scalar value, or one of the protocol parameters the
     *                                  bundle owns (a host must not be able to move `state` or `redirect_uri`)
     */
    public function setAuthorizationParams(array $params): self
    {
        $clean = [];
        foreach ($params as $name => $value) {
            $key = trim((string) $name);
            if ($key === '') {
                throw new InvalidArgumentException('An authorization parameter needs a non-empty name.');
            }
            if (in_array(strtolower($key), OAuth2Client::RESERVED_AUTHORIZATION_PARAMS, true)) {
                throw new InvalidArgumentException(sprintf('The authorization parameter "%s" is owned by the bundle and cannot be set on a credential.', $key));
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('The authorization parameter "%s" must be a scalar value or null.', $key));
            }
            $clean[$key] = $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->authorizationParams = $clean;
        $this->touch();

        return $this;
    }

    public function getAuthorizeUrl(): ?string
    {
        return $this->authorizeUrl;
    }

    public function setAuthorizeUrl(?string $authorizeUrl): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();
    }
}
