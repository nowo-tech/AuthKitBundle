<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\Doctrine\EncryptedFieldWrite;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Repository\SamlProviderRepository;
use Nowo\AuthKitBundle\Saml\SamlCertificate;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;

use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_string;
use function parse_url;
use function preg_match;
use function sprintf;
use function strlen;
use function strtolower;
use function trim;

/**
 * A SAML 2.0 identity provider and this application's Service Provider settings for it (2.10.0). One row per provider
 * key; several rows per tenant (the host's `scope`). Sign-ins link identities as `saml:<provider>` in
 * `auth_kit_social_account` ({@see self::accountProvider()}).
 *
 * IdP certificates are public and stored in plain with their SHA-256 and validity dates (expiry is a SQL query); a
 * second one allows a rollover. The SP key pairs (current and next, for rotation) are `#[Encrypted]` (nowo-tech
 * DoctrineEncrypt; a row with a private key is refused at flush without it unless
 * `doctrine.require_enterprise_secret_encryption: false`). Their certificates' expiry is copied in plain columns.
 *
 * The table and its named indexes take `nowo_auth_kit.doctrine.table_prefix` ({@see TablePrefixListener}).
 */
#[ORM\Entity(repositoryClass: SamlProviderRepository::class)]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_saml_provider_key', columns: ['provider'])]
#[ORM\Index(name: 'idx_auth_kit_saml_provider_scope', columns: ['scope'])]
class SamlProvider
{
    public const TABLE = 'auth_kit_saml_provider';

    /** Prefix of the provider key of the identities this row links (`auth_kit_social_account.provider`). */
    public const ACCOUNT_PROVIDER_PREFIX = 'saml:';

    /** Provider keys: the route key; 59 so that `saml:<key>` fits the 64 characters of the link's provider column. */
    public const PROVIDER_PATTERN = '/^[a-z0-9_-]{1,59}$/';

    public const URL_MAX_LENGTH = 512;

    /** Keys of {@see self::getAttributeMapping()}. */
    public const MAPPING_KEYS = ['subject', 'email', 'first_name', 'last_name', 'display_name', 'groups'];

    public const LINK_EXISTING_ACCOUNT_POLICIES = SocialLoginCredential::LINK_EXISTING_ACCOUNT_POLICIES;

    public const MFA_REQUIRED = 'required';

    public const MFA_EXEMPT = 'exempt';

    /** No second step only when the assertion's `AuthnContextClassRef` is one of {@see self::getMfaAcceptedAuthnContexts()}. */
    public const MFA_EXEMPT_WHEN_AUTHN_CONTEXT = 'exempt_when_authn_context';

    public const MFA_FIRST_FACTOR_POLICIES = [self::MFA_REQUIRED, self::MFA_EXEMPT, self::MFA_EXEMPT_WHEN_AUTHN_CONTEXT];

    public const MFA_ACCEPTED_AUTHN_CONTEXTS_MAX_COUNT = 16;

    public const MFA_ACCEPTED_AUTHN_CONTEXT_MAX_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns via reflection)

    #[ORM\Column(type: Types::STRING, length: 59)]
    private string $provider = '';

    #[ORM\Column(type: Types::STRING, length: 191, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $label = '';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(name: 'idp_entity_id', type: Types::STRING, length: 512)]
    private string $idpEntityId = '';

    #[ORM\Column(name: 'idp_sso_url', type: Types::STRING, length: 512)]
    private string $idpSsoUrl = '';

    #[ORM\Column(name: 'idp_certificate', type: Types::TEXT, nullable: true)]
    private ?string $idpCertificate = null;

    #[ORM\Column(name: 'idp_certificate_sha256', type: Types::STRING, length: 64, nullable: true)]
    private ?string $idpCertificateSha256 = null;

    #[ORM\Column(name: 'idp_certificate_not_before', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $idpCertificateNotBefore = null;

    #[ORM\Column(name: 'idp_certificate_not_after', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $idpCertificateNotAfter = null;

    #[ORM\Column(name: 'idp_next_certificate', type: Types::TEXT, nullable: true)]
    private ?string $idpNextCertificate = null;

    #[ORM\Column(name: 'idp_next_certificate_sha256', type: Types::STRING, length: 64, nullable: true)]
    private ?string $idpNextCertificateSha256 = null;

    #[ORM\Column(name: 'idp_next_certificate_not_before', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $idpNextCertificateNotBefore = null;

    #[ORM\Column(name: 'idp_next_certificate_not_after', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $idpNextCertificateNotAfter = null;

    /** Null: the absolute URL of the SP metadata route. */
    #[ORM\Column(name: 'sp_entity_id', type: Types::STRING, length: 512, nullable: true)]
    private ?string $spEntityId = null;

    #[ORM\Column(name: 'sp_private_key', type: Types::TEXT, nullable: true)]
    #[Encrypted]
    private ?string $spPrivateKey = null;

    #[ORM\Column(name: 'sp_certificate', type: Types::TEXT, nullable: true)]
    #[Encrypted]
    private ?string $spCertificate = null;

    #[ORM\Column(name: 'sp_certificate_not_after', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $spCertificateNotAfter = null;

    #[ORM\Column(name: 'sp_next_private_key', type: Types::TEXT, nullable: true)]
    #[Encrypted]
    private ?string $spNextPrivateKey = null;

    #[ORM\Column(name: 'sp_next_certificate', type: Types::TEXT, nullable: true)]
    #[Encrypted]
    private ?string $spNextCertificate = null;

    #[ORM\Column(name: 'sp_next_certificate_not_after', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $spNextCertificateNotAfter = null;

    #[ORM\Column(name: 'name_id_format', type: Types::STRING, length: 128, nullable: true)]
    private ?string $nameIdFormat = null;

    /**
     * Typed loosely: a row written outside the setter may hold anything; {@see self::getAttributeMapping()} keeps the
     * known keys with non-empty string values only.
     *
     * @var array<mixed>|null
     */
    #[ORM\Column(name: 'attribute_mapping', type: Types::JSON, nullable: true)]
    private ?array $attributeMapping = []; // @phpstan-ignore property.unusedType (Doctrine hydrates null from a row written outside the setter)

    #[ORM\Column(name: 'email_verified', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(name: 'want_encrypted_assertions', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $wantEncryptedAssertions = false;

    /** True: the Assertion itself must be signed (a signature on the Response alone is refused). */
    #[ORM\Column(name: 'want_assertions_signed', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $wantAssertionsSigned = false;

    /**
     * True: without a `subject` attribute only a persistent NameID is a subject (an e-mail, unspecified or any other
     * format is refused: an address can be reassigned to someone else).
     */
    #[ORM\Column(name: 'require_persistent_subject', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requirePersistentSubject = false;

    #[ORM\Column(name: 'link_existing_account', type: Types::STRING, length: 16, nullable: true)]
    private ?string $linkExistingAccount = null;

    #[ORM\Column(name: 'mfa_first_factor', type: Types::STRING, length: 32, nullable: true)]
    private ?string $mfaFirstFactor = null;

    /** @var array<mixed>|null */
    #[ORM\Column(name: 'mfa_accepted_authn_contexts', type: Types::JSON, nullable: true)]
    private ?array $mfaAcceptedAuthnContexts = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new DateTimeImmutable();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->createdAt = $now;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = $now;
    }

    /**
     * The provider key the identities of a SAML provider are linked under (`saml:<key>`).
     */
    public static function accountProvider(string $provider): string
    {
        return self::ACCOUNT_PROVIDER_PREFIX . $provider;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * @throws InvalidArgumentException unless `[a-z0-9_-]{1,59}`
     */
    public function setProvider(string $provider): self
    {
        if (preg_match(self::PROVIDER_PATTERN, $provider) !== 1) {
            throw new InvalidArgumentException('A SAML provider key is 1 to 59 characters of a-z, 0-9, "_" and "-".');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->provider = $provider;
        $this->touch();

        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * @param string|null $scope opaque host value (tenant, organization id), at most 191 characters
     */
    public function setScope(?string $scope): self
    {
        if ($scope !== null && ($scope === '' || strlen($scope) > 191)) {
            throw new InvalidArgumentException('A SAML provider scope is null or 1 to 191 bytes.');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->scope = $scope;
        $this->touch();

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $label = trim($label);
        if (strlen($label) > 128) {
            throw new InvalidArgumentException('A SAML provider label is at most 128 bytes.');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->label = $label;
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

    public function getIdpEntityId(): string
    {
        return $this->idpEntityId;
    }

    /**
     * @throws InvalidArgumentException when empty, longer than 512 bytes, or holding white space or control characters
     */
    public function setIdpEntityId(string $entityId): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpEntityId = $this->entityId($entityId, 'IdP entity id');
        $this->touch();

        return $this;
    }

    public function getIdpSsoUrl(): string
    {
        return $this->idpSsoUrl;
    }

    /**
     * @param string $url the IdP's SingleSignOnService of the HTTP-Redirect binding (`https://` only)
     */
    public function setIdpSsoUrl(string $url): self
    {
        $parts = parse_url($url);
        if (strlen($url) > self::URL_MAX_LENGTH || $parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['fragment']) || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('The IdP SSO URL must be an https:// URL of at most 512 bytes, without credentials or fragment.');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpSsoUrl = $url;
        $this->touch();

        return $this;
    }

    /**
     * Replaces the IdP signing certificates (PEM or base64 body): the current one and an optional second one accepted
     * during a rollover.
     *
     * @throws InvalidArgumentException when a certificate cannot be read, or both are the same
     */
    public function setIdpCertificates(string $current, ?string $next = null): self
    {
        $certificate = SamlCertificate::fromPem($current);
        $second      = $next !== null && trim($next) !== '' ? SamlCertificate::fromPem($next) : null;
        if ($second instanceof SamlCertificate && $second->sha256 === $certificate->sha256) {
            throw new InvalidArgumentException('The second IdP certificate is the same as the first one.');
        }
        // @igor-ignore - Doctrine entity fields; instance-scoped, not a shared service.
        $this->idpCertificate = $certificate->pem;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpCertificateSha256 = $certificate->sha256;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpCertificateNotBefore = $certificate->notBefore;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpCertificateNotAfter = $certificate->notAfter;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpNextCertificate = $second?->pem;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpNextCertificateSha256 = $second?->sha256;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpNextCertificateNotBefore = $second?->notBefore;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->idpNextCertificateNotAfter = $second?->notAfter;
        $this->touch();

        return $this;
    }

    public function getIdpCertificate(): ?string
    {
        return $this->idpCertificate;
    }

    public function getIdpCertificateSha256(): ?string
    {
        return $this->idpCertificateSha256;
    }

    public function getIdpCertificateNotBefore(): ?DateTimeImmutable
    {
        return $this->idpCertificateNotBefore;
    }

    public function getIdpCertificateNotAfter(): ?DateTimeImmutable
    {
        return $this->idpCertificateNotAfter;
    }

    public function getIdpNextCertificate(): ?string
    {
        return $this->idpNextCertificate;
    }

    public function getIdpNextCertificateSha256(): ?string
    {
        return $this->idpNextCertificateSha256;
    }

    public function getIdpNextCertificateNotBefore(): ?DateTimeImmutable
    {
        return $this->idpNextCertificateNotBefore;
    }

    public function getIdpNextCertificateNotAfter(): ?DateTimeImmutable
    {
        return $this->idpNextCertificateNotAfter;
    }

    /**
     * The accepted IdP signing certificates (PEM): the current one, then the second one.
     *
     * @return list<string>
     */
    public function getIdpCertificates(): array
    {
        $certificates = [];
        foreach ([$this->idpCertificate, $this->idpNextCertificate] as $certificate) {
            if ($certificate !== null && $certificate !== '') {
                $certificates[] = $certificate;
            }
        }

        return $certificates;
    }

    public function getSpEntityId(): ?string
    {
        return $this->spEntityId;
    }

    /**
     * @param string|null $entityId null: the absolute URL of the SP metadata route
     */
    public function setSpEntityId(?string $entityId): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spEntityId = $entityId === null ? null : $this->entityId($entityId, 'SP entity id');
        $this->touch();

        return $this;
    }

    /**
     * Sets the current SP key pair (signs AuthnRequests, decrypts assertions, published in the metadata).
     *
     * @throws InvalidArgumentException when the certificate cannot be read or the key does not belong to it
     */
    public function setSpKeyPair(string $privateKeyPem, string $certificatePem): self
    {
        $certificate = $this->keyPair($privateKeyPem, $certificatePem);
        $changed     = EncryptedFieldWrite::changes($this->spPrivateKey, $privateKeyPem) || EncryptedFieldWrite::changes($this->spCertificate, $certificate->pem);
        // @igor-ignore - Doctrine entity fields; instance-scoped, not a shared service.
        $this->spPrivateKey = $privateKeyPem;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spCertificate = $certificate->pem;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spCertificateNotAfter = $certificate->notAfter;
        if ($changed) {
            $this->touch();
        }

        return $this;
    }

    /**
     * Sets (or, with two nulls, clears) the next SP key pair: published next to the current one and accepted for
     * decryption until {@see self::promoteNextSpKeyPair()}.
     *
     * @throws InvalidArgumentException when only one value is given, the certificate cannot be read or the key does not
     *                                  belong to it
     */
    public function setNextSpKeyPair(?string $privateKeyPem, ?string $certificatePem): self
    {
        if (($privateKeyPem === null) !== ($certificatePem === null)) {
            throw new InvalidArgumentException('The next SP key pair needs both the private key and its certificate (or neither).');
        }
        $certificate = $privateKeyPem !== null && $certificatePem !== null ? $this->keyPair($privateKeyPem, $certificatePem) : null;
        // @igor-ignore - Doctrine entity fields; instance-scoped, not a shared service.
        $this->spNextPrivateKey = $privateKeyPem;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spNextCertificate = $certificate?->pem;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spNextCertificateNotAfter = $certificate?->notAfter;
        $this->touch();

        return $this;
    }

    /**
     * The next SP key pair becomes the current one; the next slot is emptied.
     *
     * @throws LogicException when there is no next key pair
     */
    public function promoteNextSpKeyPair(): self
    {
        if ($this->spNextPrivateKey === null || $this->spNextCertificate === null) {
            throw new LogicException('There is no next SP key pair to promote.');
        }
        // @igor-ignore - Doctrine entity fields; instance-scoped, not a shared service.
        $this->spPrivateKey = $this->spNextPrivateKey;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spCertificate = $this->spNextCertificate;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spCertificateNotAfter = $this->spNextCertificateNotAfter;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spNextPrivateKey = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spNextCertificate = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->spNextCertificateNotAfter = null;
        $this->touch();

        return $this;
    }

    public function getSpPrivateKey(): ?string
    {
        return $this->spPrivateKey;
    }

    public function getSpCertificate(): ?string
    {
        return $this->spCertificate;
    }

    public function getSpCertificateNotAfter(): ?DateTimeImmutable
    {
        return $this->spCertificateNotAfter;
    }

    public function getSpNextPrivateKey(): ?string
    {
        return $this->spNextPrivateKey;
    }

    public function getSpNextCertificate(): ?string
    {
        return $this->spNextCertificate;
    }

    public function getSpNextCertificateNotAfter(): ?DateTimeImmutable
    {
        return $this->spNextCertificateNotAfter;
    }

    public function getNameIdFormat(): ?string
    {
        return $this->nameIdFormat;
    }

    /**
     * @param string|null $format the `NameIDPolicy/@Format` requested (e.g. `urn:oasis:names:tc:SAML:2.0:nameid-format:persistent`); null: unspecified
     */
    public function setNameIdFormat(?string $format): self
    {
        if ($format !== null && preg_match('/^urn:[A-Za-z0-9:._-]{1,124}$/', $format) !== 1) {
            throw new InvalidArgumentException('A NameID format is a "urn:" URI of at most 128 characters.');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nameIdFormat = $format;
        $this->touch();

        return $this;
    }

    /**
     * Attribute names (`Name`, or `FriendlyName`) per mapped value: `subject`, `email`, `first_name`, `last_name`,
     * `display_name`, `groups`. Unknown keys and empty values of a row written outside the setter are dropped.
     *
     * @return array<string, string>
     */
    public function getAttributeMapping(): array
    {
        $mapping = [];
        foreach (self::MAPPING_KEYS as $key) {
            $name = $this->attributeMapping[$key] ?? null;
            if (is_string($name) && $name !== '') {
                $mapping[$key] = $name;
            }
        }

        return $mapping;
    }

    /**
     * @param array<string, string|null> $mapping keys of {@see self::MAPPING_KEYS}; a null or empty value removes the key
     */
    public function setAttributeMapping(array $mapping): self
    {
        $clean = [];
        foreach ($mapping as $key => $name) {
            if (!in_array($key, self::MAPPING_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown SAML attribute mapping key "%s" (%s).', $key, implode(', ', self::MAPPING_KEYS)));
            }
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if (strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                throw new InvalidArgumentException(sprintf('The SAML attribute name of "%s" is at most 255 bytes without control characters.', $key));
            }
            $clean[$key] = $name;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->attributeMapping = $clean;
        $this->touch();

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    /**
     * @param bool $verified true: the IdP is authoritative for the e-mail it asserts (it counts as verified for linking
     *                       and creation under `social_login.require_verified_email`)
     */
    public function setEmailVerified(bool $verified): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->emailVerified = $verified;
        $this->touch();

        return $this;
    }

    public function isWantEncryptedAssertions(): bool
    {
        return $this->wantEncryptedAssertions;
    }

    public function setWantEncryptedAssertions(bool $want): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->wantEncryptedAssertions = $want;
        $this->touch();

        return $this;
    }

    public function isWantAssertionsSigned(): bool
    {
        return $this->wantAssertionsSigned;
    }

    /**
     * @param bool $want true: a response whose Assertion is not signed itself is refused, even with a signed Response
     */
    public function setWantAssertionsSigned(bool $want): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->wantAssertionsSigned = $want;
        $this->touch();

        return $this;
    }

    public function isRequirePersistentSubject(): bool
    {
        return $this->requirePersistentSubject;
    }

    public function setRequirePersistentSubject(bool $require): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->requirePersistentSubject = $require;
        $this->touch();

        return $this;
    }

    /**
     * The row's own `link_existing_account`, or null for the profile's; an unknown stored value reads `deny`.
     */
    public function getLinkExistingAccount(): ?string
    {
        if ($this->linkExistingAccount !== null && !in_array($this->linkExistingAccount, self::LINK_EXISTING_ACCOUNT_POLICIES, true)) {
            return 'deny';
        }

        return $this->linkExistingAccount;
    }

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
     * The row's own MFA first-factor policy, or null for `mfa.first_factors.social`; an unknown stored value reads
     * `required`.
     */
    public function getMfaFirstFactor(): ?string
    {
        if ($this->mfaFirstFactor !== null && !in_array($this->mfaFirstFactor, self::MFA_FIRST_FACTOR_POLICIES, true)) {
            return self::MFA_REQUIRED;
        }

        return $this->mfaFirstFactor;
    }

    /**
     * @return list<string>
     */
    public function getMfaAcceptedAuthnContexts(): array
    {
        $values = [];
        foreach ($this->mfaAcceptedAuthnContexts ?? [] as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param string|null $policy required | exempt | exempt_when_authn_context; null = `mfa.first_factors.social`
     * @param list<string> $acceptedAuthnContexts `AuthnContextClassRef` values that exempt; required (non-empty) with
     *                                            exempt_when_authn_context, refused with any other policy
     */
    public function setMfaFirstFactorPolicy(?string $policy, array $acceptedAuthnContexts = []): self
    {
        if ($policy !== null && !in_array($policy, self::MFA_FIRST_FACTOR_POLICIES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown MFA first-factor policy "%s" (required, exempt or exempt_when_authn_context).', $policy));
        }
        $values = array_values(array_unique($acceptedAuthnContexts));
        if (($policy === self::MFA_EXEMPT_WHEN_AUTHN_CONTEXT) !== ($values !== [])) {
            throw new InvalidArgumentException('Accepted AuthnContextClassRef values go with the exempt_when_authn_context policy only, which needs at least one.');
        }
        if (count($values) > self::MFA_ACCEPTED_AUTHN_CONTEXTS_MAX_COUNT) {
            throw new InvalidArgumentException(sprintf('At most %d accepted AuthnContextClassRef values.', self::MFA_ACCEPTED_AUTHN_CONTEXTS_MAX_COUNT));
        }
        foreach ($values as $value) {
            if ($value === '' || strlen($value) > self::MFA_ACCEPTED_AUTHN_CONTEXT_MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f]/', $value) === 1) {
                throw new InvalidArgumentException('An accepted AuthnContextClassRef is a URI of at most 255 bytes without white space.');
            }
        }
        // @igor-ignore - Doctrine entity fields; instance-scoped, not a shared service.
        $this->mfaFirstFactor = $policy;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mfaAcceptedAuthnContexts = $values === [] ? null : $values;
        $this->touch();

        return $this;
    }

    /**
     * Whether every value a sign-in needs is set (IdP entity id, SSO URL and certificate, the current SP key pair).
     */
    public function isComplete(): bool
    {
        return $this->provider !== '' && $this->idpEntityId !== '' && $this->idpSsoUrl !== '' && $this->getIdpCertificates() !== []
            && $this->spPrivateKey !== null && $this->spPrivateKey !== '' && $this->spCertificate !== null && $this->spCertificate !== '';
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

    private function entityId(string $entityId, string $label): string
    {
        if ($entityId === '' || strlen($entityId) > self::URL_MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f]/', $entityId) === 1) {
            throw new InvalidArgumentException(sprintf('The %s is 1 to 512 bytes without white space or control characters.', $label));
        }

        return $entityId;
    }

    private function keyPair(string $privateKeyPem, string $certificatePem): SamlCertificate
    {
        $certificate = SamlCertificate::fromPem($certificatePem);
        if (!$certificate->matchesPrivateKey($privateKeyPem)) {
            throw new InvalidArgumentException('The SP private key cannot be read or does not belong to the certificate.');
        }

        return $certificate;
    }
}
