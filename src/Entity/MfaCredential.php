<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;

/**
 * Second-factor state of one user of one profile (1.34): the TOTP secret (encrypted at rest with
 * nowo-tech/doctrine-encrypt-bundle), when it was enabled, the last accepted time step (replay protection, written
 * with a conditional UPDATE) and the grace start of a required user. Users are referenced by a stable owner key
 * ({@see MfaOwner}: Doctrine root class + identifier), never by their e-mail.
 *
 * The table and its named indexes take `nowo_auth_kit.doctrine.table_prefix` ({@see TablePrefixListener}).
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_mfa_owner', columns: ['profile', 'user_type', 'user_id'])]
class MfaCredential
{
    public const TABLE = 'auth_kit_mfa';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (assigned by Doctrine) */
    private ?int $id = null;

    /** Base32 TOTP secret; null while MFA is off. */
    #[ORM\Column(name: 'totp_secret', type: Types::TEXT, nullable: true)]
    #[Encrypted]
    private ?string $totpSecret = null;

    #[ORM\Column(name: 'enabled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $enabledAt = null;

    /** Informative only: replay checks use the conditional UPDATE of the store. */
    #[ORM\Column(name: 'last_used_step', type: Types::BIGINT, nullable: true)]
    private ?int $lastUsedStep = null;

    #[ORM\Column(name: 'required_since', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $requiredSince = null;

    #[ORM\Column(name: 'reset_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $resetAt = null;

    #[ORM\Column(name: 'reset_by', type: Types::STRING, length: 180, nullable: true)]
    private ?string $resetBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $profile,
        #[ORM\Column(name: 'user_type', type: Types::STRING, length: 191)]
        private string $userType,
        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 191)]
        private string $userId,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function owner(): MfaOwner
    {
        return new MfaOwner($this->userType, $this->userId);
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function isEnabled(): bool
    {
        return $this->totpSecret !== null && $this->enabledAt instanceof DateTimeImmutable;
    }

    public function getEnabledAt(): ?DateTimeImmutable
    {
        return $this->enabledAt;
    }

    /**
     * Last accepted TOTP time step when this entity was loaded (the store's conditional UPDATE is authoritative).
     */
    public function getLastUsedStep(): ?int
    {
        return $this->lastUsedStep;
    }

    public function getRequiredSince(): ?DateTimeImmutable
    {
        return $this->requiredSince;
    }

    public function getResetAt(): ?DateTimeImmutable
    {
        return $this->resetAt;
    }

    public function getResetBy(): ?string
    {
        return $this->resetBy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Turns MFA on with a confirmed secret (the replay marker starts at the confirming step).
     */
    public function enable(string $secret, int $confirmedStep, DateTimeImmutable $now): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->totpSecret    = $secret;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->enabledAt     = $now;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastUsedStep  = $confirmedStep;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->requiredSince = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt     = $now;
    }

    /**
     * Turns MFA off (own action or admin reset); `$resetBy` records an administrator.
     */
    public function disable(DateTimeImmutable $now, ?string $resetBy = null): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->totpSecret   = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->enabledAt    = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastUsedStep = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt    = $now;
        if ($resetBy !== null) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->resetAt = $now;
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->resetBy = $resetBy;
        }
    }

    public function markRequiredSince(?DateTimeImmutable $since, DateTimeImmutable $now): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->requiredSince = $since;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt     = $now;
    }
}
