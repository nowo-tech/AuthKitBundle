<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuthKitBundle\Repository\PasswordResetRequestRepository;

/**
 * The pending reset credential of a user when `password_reset.storage: table` (1.46.0, REQ-ARCH-001 rule 2): the reset
 * credential lives here instead of on the host user, which is referenced by class + id (or by identifier when the
 * user has no id), with no Doctrine relation. At most one row per user. Only SHA-256 hashes are stored: `token_hash`
 * holds the link token hash (link / both) or the code hash (code), `code_hash` the code hash of `both` deliveries.
 *
 * Written and read with DBAL statements by {@see PasswordResetRequestRepository} (fresh reads in worker mode, no flush
 * of any entity manager); the entity maps the schema (protected properties: they are read by SQL only).
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_auth_kit_password_reset_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_auth_kit_password_reset_user', columns: ['user_class', 'user_id'])]
class PasswordResetRequest
{
    public const TABLE = 'auth_kit_password_reset';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (assigned by Doctrine) */
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 64)]
        protected string $profile,
        #[ORM\Column(name: 'user_class', type: Types::STRING, length: 255)]
        protected string $userClass,
        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 191, nullable: true)]
        protected ?string $userId,
        #[ORM\Column(name: 'user_identifier', type: Types::STRING, length: 255)]
        protected string $userIdentifier,
        #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
        protected string $tokenHash,
        #[ORM\Column(name: 'code_hash', type: Types::STRING, length: 64, nullable: true)]
        protected ?string $codeHash,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
