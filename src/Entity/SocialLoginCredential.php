<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcCredentialRules;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;

use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function preg_match;
use function sprintf;
use function strlen;
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
    /** 2.7.0: `link_existing_account` values a credential may override the profile's with (null = the profile's). */
    public const LINK_EXISTING_ACCOUNT_POLICIES = ['reset_password', 'deny', 'keep'];

    /** 2.7.0: the second step follows the user's MFA, as for any first factor. */
    public const MFA_REQUIRED = 'required';

    /** 2.7.0: no second step after this credential (forced enrolment still applies). */
    public const MFA_EXEMPT = 'exempt';

    /** 2.7.0: no second step only when the validated ID token's `amr` names one of {@see self::getMfaAcceptedAmr()}. */
    public const MFA_EXEMPT_WHEN_AMR = 'exempt_when_amr';

    public const MFA_FIRST_FACTOR_POLICIES = [self::MFA_REQUIRED, self::MFA_EXEMPT, self::MFA_EXEMPT_WHEN_AMR];

    /** Most `amr` values one credential accepts, and the longest one (RFC 8176 values are short tokens). */
    public const MFA_ACCEPTED_AMR_MAX_COUNT = 16;

    public const MFA_ACCEPTED_AMR_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns via reflection)

    /** Provider key (google, github, microsoft, gitlab, apple, linkedin, slack, or custom slug). */
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

    /**
     * Nullable on the PHP side although the column is NOT NULL (1.48, audit M-1): a column added to a non-empty
     * table by the single-statement migration Doctrine generates leaves the existing rows holding a JSON scalar
     * `null` (MySQL) or the empty string (MariaDB), which hydrates as PHP null. A non-nullable property would then
     * raise a TypeError while hydrating and take every page that consults the social gate down with it. The getters
     * answer `[]` instead; see UPGRADING for the migration that fixes the rows.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON)]
    private ?array $scopes = []; // @phpstan-ignore property.unusedType (Doctrine hydrates null from such a row)

    /**
     * Extra parameters merged into the authorization request of this provider (1.48), e.g.
     * `['prompt' => 'select_account', 'access_type' => 'offline']`. A null value removes a parameter the bundle
     * would otherwise send for a built-in provider. The protocol parameters the bundle owns
     * ({@see OAuth2Client::RESERVED_AUTHORIZATION_PARAMS}) are refused here and ignored when read.
     *
     * Nullable on the PHP side for the reason given on {@see self::$scopes} (audit M-1).
     *
     * @var array<string, string|null>|null
     */
    #[ORM\Column(name: 'authorization_params', type: Types::JSON)]
    private ?array $authorizationParams = []; // @phpstan-ignore property.unusedType (Doctrine hydrates null from such a row)

    #[ORM\Column(name: 'authorize_url', type: Types::STRING, length: 512, nullable: true)]
    private ?string $authorizeUrl = null;

    #[ORM\Column(name: 'token_url', type: Types::STRING, length: 512, nullable: true)]
    private ?string $tokenUrl = null;

    #[ORM\Column(name: 'userinfo_url', type: Types::STRING, length: 512, nullable: true)]
    private ?string $userinfoUrl = null;

    /**
     * OpenID Connect issuer (2.3.0): the generic provider's issuer (discovery at `<issuer>/.well-known/openid-configuration`),
     * or a self-hosted GitLab's base URL. Null for the OAuth2 providers and for the presets that use their own issuer.
     */
    #[ORM\Column(name: 'issuer', type: Types::STRING, length: 512, nullable: true)]
    private ?string $issuer = null;

    /**
     * Per-provider settings that are not secrets (2.3.0): Apple's `team_id` and `key_id`. Validated by
     * {@see OidcCredentialRules::settingsError()}.
     *
     * @var array<string, string|null>|null
     */
    #[ORM\Column(name: 'provider_settings', type: Types::JSON, nullable: true)]
    private ?array $providerSettings = null;

    /**
     * 2.7.0: false keeps the credential out of the provider buttons (`social_links` / `sso_links`, the invitation accept
     * page); it is started only by name (`nowo_auth_kit_social_login_start`). Null (every row before 2.7.0) = listed.
     */
    #[ORM\Column(name: 'listed', type: Types::BOOLEAN, nullable: true)]
    private ?bool $listed = null;

    /**
     * 2.7.0: `link_existing_account` for this credential (reset_password | deny | keep); null = the profile's
     * `social_login.link_existing_account`.
     */
    #[ORM\Column(name: 'link_existing_account', type: Types::STRING, length: 16, nullable: true)]
    private ?string $linkExistingAccount = null;

    /**
     * 2.7.0: MFA first-factor policy of a sign-in with this credential (required | exempt | exempt_when_amr); null = the
     * profile's `mfa.first_factors.social`.
     */
    #[ORM\Column(name: 'mfa_first_factor', type: Types::STRING, length: 16, nullable: true)]
    private ?string $mfaFirstFactor = null;

    /**
     * 2.7.0: `amr` values (RFC 8176) that exempt a sign-in with `mfa_first_factor: exempt_when_amr`. Typed loosely: a
     * row written outside the setter may hold anything, and the getter keeps only non-empty strings.
     *
     * @var array<mixed>|null
     */
    #[ORM\Column(name: 'mfa_accepted_amr', type: Types::JSON, nullable: true)]
    private ?array $mfaAcceptedAmr = null;

    /**
     * 2.8.0: OpenID Connect RP-Initiated Logout for sign-ins with this credential. true: signing out of the application
     * also sends the browser to the provider's `end_session_endpoint` (after the local session is gone). Null (every
     * row before 2.8.0) or false: local sign-out only.
     */
    #[ORM\Column(name: 'rp_initiated_logout', type: Types::BOOLEAN, nullable: true)]
    private ?bool $rpInitiatedLogout = null;

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
        // Trimmed (1.48, audit L-3): edge white space made a credential match the route and the brand mark but not
        // the per-provider settings (PKCE, authorization_params), so it silently sent no code_challenge.
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->provider = trim($provider);
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
        return $this->scopes ?? [];
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
        return $this->authorizationParams ?? [];
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

    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    /**
     * @throws InvalidArgumentException when the issuer is not a public HTTPS URL without query, fragment or credentials
     */
    public function setIssuer(?string $issuer): self
    {
        $issuer = $issuer === null ? null : trim($issuer);
        if ($issuer === '') {
            $issuer = null;
        }
        if ($issuer !== null && ($error = OidcCredentialRules::issuerError($issuer)) !== null) {
            throw new InvalidArgumentException($error);
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->issuer = $issuer;
        $this->touch();

        return $this;
    }

    /**
     * @return array<string, string|null>
     */
    public function getProviderSettings(): array
    {
        return $this->providerSettings ?? [];
    }

    /**
     * @param array<string, string|null> $settings `team_id` and `key_id` (Apple); a null value clears one
     *
     * @throws InvalidArgumentException for an unknown key or a malformed identifier
     */
    public function setProviderSettings(array $settings): self
    {
        if (($error = OidcCredentialRules::settingsError($settings)) !== null) {
            throw new InvalidArgumentException($error);
        }
        $clean = [];
        foreach ($settings as $key => $value) {
            if ($value !== null) {
                $clean[$key] = $value;
            }
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->providerSettings = $clean === [] ? null : $clean;
        $this->touch();

        return $this;
    }

    /**
     * Whether the credential is offered among the provider buttons (2.7.0; true for every row before 2.7.0).
     */
    public function isListed(): bool
    {
        return $this->listed !== false;
    }

    /**
     * @param bool|null $listed false: started only by name from the host (`login_hint` may be passed); null = listed
     */
    public function setListed(?bool $listed): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->listed = $listed;
        $this->touch();

        return $this;
    }

    /**
     * The credential's own `link_existing_account` (2.7.0), or null for the profile's.
     */
    public function getLinkExistingAccount(): ?string
    {
        // A value written outside the setter (SQL) that is not a known policy is read as the strictest one.
        if ($this->linkExistingAccount !== null && !in_array($this->linkExistingAccount, self::LINK_EXISTING_ACCOUNT_POLICIES, true)) {
            return 'deny';
        }

        return $this->linkExistingAccount;
    }

    /**
     * @param string|null $policy reset_password | deny | keep; null = the profile's `social_login.link_existing_account`
     *
     * @throws InvalidArgumentException for any other value
     */
    public function setLinkExistingAccount(?string $policy): self
    {
        if ($policy !== null && !in_array($policy, self::LINK_EXISTING_ACCOUNT_POLICIES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown link_existing_account policy "%s" (reset_password, deny or keep).', $policy));
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->linkExistingAccount = $policy;
        $this->touch();

        return $this;
    }

    /**
     * The credential's own MFA first-factor policy (2.7.0), or null for the profile's `mfa.first_factors.social`.
     */
    public function getMfaFirstFactor(): ?string
    {
        // Fail closed: a value written outside the setter that is not a known policy asks for the second step.
        if ($this->mfaFirstFactor !== null && !in_array($this->mfaFirstFactor, self::MFA_FIRST_FACTOR_POLICIES, true)) {
            return self::MFA_REQUIRED;
        }

        return $this->mfaFirstFactor;
    }

    /**
     * The `amr` values that exempt a sign-in under `exempt_when_amr` (2.7.0); only non-empty strings are returned.
     *
     * @return list<string>
     */
    public function getMfaAcceptedAmr(): array
    {
        $values = [];
        foreach ($this->mfaAcceptedAmr ?? [] as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Sets the MFA first-factor policy of this credential (2.7.0).
     *
     * @param string|null $policy required | exempt | exempt_when_amr; null = the profile's `mfa.first_factors.social`
     * @param list<string> $acceptedAmr `amr` values that exempt the sign-in; required (non-empty) with exempt_when_amr,
     *                                  refused with any other policy
     *
     * @throws InvalidArgumentException for an unknown policy or an invalid `amr` list
     */
    public function setMfaFirstFactorPolicy(?string $policy, array $acceptedAmr = []): self
    {
        if ($policy !== null && !in_array($policy, self::MFA_FIRST_FACTOR_POLICIES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown MFA first-factor policy "%s" (required, exempt or exempt_when_amr).', $policy));
        }
        if ($policy !== self::MFA_EXEMPT_WHEN_AMR && $acceptedAmr !== []) {
            throw new InvalidArgumentException('Accepted amr values are only used with the exempt_when_amr policy.');
        }
        if ($policy === self::MFA_EXEMPT_WHEN_AMR) {
            if ($acceptedAmr === [] || count($acceptedAmr) > self::MFA_ACCEPTED_AMR_MAX_COUNT) {
                throw new InvalidArgumentException(sprintf('The exempt_when_amr policy needs between 1 and %d accepted amr values.', self::MFA_ACCEPTED_AMR_MAX_COUNT));
            }
            foreach ($acceptedAmr as $value) {
                if (strlen($value) > self::MFA_ACCEPTED_AMR_MAX_LENGTH || preg_match('/^[\x21-\x7E]+$/D', $value) !== 1) {
                    throw new InvalidArgumentException('An accepted amr value must be a non-empty printable ASCII token without spaces (at most 64 characters).');
                }
            }
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mfaFirstFactor = $policy;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mfaAcceptedAmr = $policy === self::MFA_EXEMPT_WHEN_AMR ? array_values(array_unique($acceptedAmr)) : null;
        $this->touch();

        return $this;
    }

    /**
     * Whether a sign-out also ends the provider session (2.8.0, OpenID Connect providers only; false for every row
     * before 2.8.0).
     */
    public function isRpInitiatedLogout(): bool
    {
        return $this->rpInitiatedLogout === true;
    }

    /**
     * @param bool|null $enabled true: RP-initiated logout at the provider's `end_session_endpoint`; null or false: off
     */
    public function setRpInitiatedLogout(?bool $enabled): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->rpInitiatedLogout = $enabled;
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
