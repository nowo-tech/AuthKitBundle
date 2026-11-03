<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One single-use recovery code of an {@see MfaCredential} (1.34): only its HMAC is stored. Written and consumed with
 * DBAL statements (conditional `UPDATE … used_at IS NULL`); the entity maps the schema (protected properties: they are
 * read by SQL only).
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_mfa_recovery_code', columns: ['mfa_id', 'code_hash'])]
class MfaRecoveryCode
{
    public const TABLE = 'auth_kit_mfa_recovery_code';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (assigned by Doctrine) */
    private ?int $id = null;

    /** Written by DBAL (conditional single-use UPDATE). */
    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\Column(name: 'mfa_id', type: Types::INTEGER)]
        protected int $mfaId,
        #[ORM\Column(name: 'code_hash', type: Types::STRING, length: 64)]
        protected string $codeHash,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        protected DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
