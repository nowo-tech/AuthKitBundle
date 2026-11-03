<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A browser trusted to skip the second step of an {@see MfaCredential} until `expires_at` (1.34). Only the HMAC of
 * its cookie token is stored. Written with DBAL statements; the entity maps the schema (protected properties: they are
 * read by SQL only).
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_mfa_device_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_mfa_device_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_auth_kit_mfa_device_mfa', columns: ['mfa_id', 'expires_at'])]
class MfaTrustedDevice
{
    public const TABLE = 'auth_kit_mfa_trusted_device';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (assigned by Doctrine) */
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'public_id', type: Types::STRING, length: 32)]
        protected string $publicId,
        #[ORM\Column(name: 'mfa_id', type: Types::INTEGER)]
        protected int $mfaId,
        #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
        protected string $tokenHash,
        #[ORM\Column(type: Types::STRING, length: 64)]
        protected string $label,
        #[ORM\Column(name: 'device_id', type: Types::STRING, length: 64, nullable: true)]
        protected ?string $deviceId,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $lastUsedAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $expiresAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
