<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuthKitBundle\Doctrine\IdentityCollationListener;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;

/**
 * A passkey (WebAuthn public key credential) of one user of one profile (2.4.0). Users are referenced by the MFA owner
 * key (Doctrine root class + identifier, {@see MfaOwner}), never by their e-mail.
 *
 * Exact keys (REQ-SEC-006): the credential is found by `credential_id_hash` (SHA-256 of the raw credential id, the
 * unique key) and the base64url `credential_id` is compared byte for byte in PHP; the owner columns, the opaque
 * `user_handle` and `public_id` get the binary NO PAD collation on MySQL / MariaDB ({@see IdentityCollationListener}).
 * Rows are written by DBAL statements of {@see WebauthnStore} (the sign counter by a conditional UPDATE); this class is
 * the mapping and a read model for standard hosts.
 *
 * `rp_id` (2.6.0, nullable): the WebAuthn RP id the passkey was created for, so a changed RP id is reported as such
 * ({@see WebauthnFailure::RP_ID_MISMATCH}) and the passkeys it orphans can be counted.
 *
 * The table and its named indexes take `nowo_auth_kit.doctrine.table_prefix` ({@see TablePrefixListener}).
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_webauthn_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_webauthn_credential', columns: ['credential_id_hash'])]
#[ORM\Index(name: 'idx_auth_kit_webauthn_owner', columns: ['profile', 'user_type', 'user_id'])]
class WebauthnCredential
{
    public const TABLE = 'auth_kit_webauthn_credential';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (assigned by Doctrine) */
    private ?int $id = null;

    /**
     * @param list<string> $transports
     */
    public function __construct(
        #[ORM\Column(name: 'public_id', type: Types::STRING, length: 32)]
        private string $publicId,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $profile,
        #[ORM\Column(name: 'user_type', type: Types::STRING, length: 191)]
        private string $userType,
        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 191)]
        private string $userId,
        #[ORM\Column(name: 'credential_id_hash', type: Types::STRING, length: 64)]
        private string $credentialIdHash,
        #[ORM\Column(name: 'credential_id', type: Types::TEXT)]
        private string $credentialId,
        #[ORM\Column(name: 'user_handle', type: Types::STRING, length: 64)]
        private string $userHandle,
        #[ORM\Column(name: 'public_key', type: Types::TEXT)]
        private string $publicKey,
        #[ORM\Column(name: 'sign_count', type: Types::BIGINT)]
        private int $signCount,
        #[ORM\Column(type: Types::JSON)]
        private array $transports,
        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $aaguid,
        #[ORM\Column(name: 'backup_eligible', type: Types::BOOLEAN, nullable: true)]
        private ?bool $backupEligible,
        #[ORM\Column(name: 'backup_state', type: Types::BOOLEAN, nullable: true)]
        private ?bool $backupState,
        #[ORM\Column(type: Types::STRING, length: 100)]
        private string $label,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $lastUsedAt = null,
        #[ORM\Column(name: 'rp_id', type: Types::STRING, length: 253, nullable: true)]
        private ?string $rpId = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function getUserType(): string
    {
        return $this->userType;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getCredentialIdHash(): string
    {
        return $this->credentialIdHash;
    }

    /** Base64url of the raw credential id. */
    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    /** Base64url of the opaque WebAuthn user handle (an HMAC, never personal data). */
    public function getUserHandle(): string
    {
        return $this->userHandle;
    }

    /** Base64url of the COSE public key. */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getSignCount(): int
    {
        return $this->signCount;
    }

    /**
     * @return list<string>
     */
    public function getTransports(): array
    {
        return $this->transports;
    }

    public function getAaguid(): ?string
    {
        return $this->aaguid;
    }

    public function isBackupEligible(): ?bool
    {
        return $this->backupEligible;
    }

    public function isBackedUp(): ?bool
    {
        return $this->backupState;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * 2.6.0: the RP id the passkey was created for; null for a passkey created before 2.6.0 and not backfilled (it was
     * created for the RP id configured then).
     */
    public function getRpId(): ?string
    {
        return $this->rpId;
    }
}
