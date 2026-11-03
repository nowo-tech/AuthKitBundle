<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account signed in in one browser (1.34, `multi_account`). The browser holds an opaque token per account in the
 * vault cookie; the server keeps only its HMAC (`token_hash`, plus the previous one for the rotation grace) and a
 * blob sealed with a key derived from the token (the parked session id and remember-me cookie). Without the cookie
 * the row reveals no session. Written with DBAL statements; the entity maps the schema (protected properties: read by
 * SQL only). Table-prefix aware.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_account_vault_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_account_vault_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_auth_kit_account_vault_previous', columns: ['previous_token_hash'])]
#[ORM\Index(name: 'idx_auth_kit_account_vault_user', columns: ['profile', 'user_identifier'])]
#[ORM\Index(name: 'idx_auth_kit_account_vault_expires', columns: ['expires_at'])]
class AccountVaultEntry
{
    public const TABLE = 'auth_kit_account_vault';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'public_id', type: Types::STRING, length: 32)]
        protected string $publicId,
        #[ORM\Column(type: Types::STRING, length: 64)]
        protected string $profile,
        #[ORM\Column(name: 'user_identifier', type: Types::STRING, length: 191)]
        protected string $userIdentifier,
        #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
        protected string $tokenHash,
        #[ORM\Column(name: 'previous_token_hash', type: Types::STRING, length: 64, nullable: true)]
        protected ?string $previousTokenHash,
        #[ORM\Column(name: 'rotated_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $rotatedAt,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        protected ?string $sealed,
        #[ORM\Column(name: 'previous_sealed', type: Types::TEXT, nullable: true)]
        protected ?string $previousSealed,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $lastUsedAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $expiresAt,
    ) {
    }
}
