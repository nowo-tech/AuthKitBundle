<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Repository\InvitationRepository;

use function array_unique;
use function array_values;
use function bin2hex;
use function mb_strtolower;
use function mb_substr;
use function random_bytes;
use function sprintf;
use function trim;

/**
 * Bundle-owned invitation to create an account by e-mail (`invitations.mode: enabled`). Nothing exists in the user
 * table until it is accepted; the roles (and the host payload, applied by the {@see InvitationGrantInterface}
 * services) are applied then. Only the SHA-256 hash of the link token is stored, never the token. The status is
 * derived from the dates ({@see self::status()}).
 *
 * The table and its named indexes take `nowo_auth_kit.doctrine.table_prefix` ({@see TablePrefixListener}).
 * Users are referenced by their security identifier (the bundle does not know the host user entity).
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'auth_kit_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_invitation_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_auth_kit_invitation_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_auth_kit_invitation_email', columns: ['profile', 'email', 'accepted_at', 'revoked_at'])]
#[ORM\Index(name: 'idx_auth_kit_invitation_expires', columns: ['expires_at'])]
class Invitation
{
    public const MESSAGE_MAX_LENGTH = 500;

    public const EMAIL_MAX_LENGTH = 180;

    public const IDENTIFIER_MAX_LENGTH = 180;

    /** Requirement of the public id in the administration routes. */
    public const PUBLIC_ID_REQUIREMENT = '[0-9a-f]{32}';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (assigned by Doctrine) */
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', type: Types::STRING, length: 32)]
    private string $publicId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $profile;

    #[ORM\Column(type: Types::STRING, length: self::EMAIL_MAX_LENGTH)]
    private string $email;

    /** @var list<string> roles granted on acceptance (besides the profile registration_role) */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** @var array<string, mixed> host data (pre-filled registration fields, grants applied by the host) */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
    private string $tokenHash = '';

    #[ORM\Column(name: 'ttl_days', type: Types::SMALLINT)]
    private int $ttlDays;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $sentAt;

    #[ORM\Column(name: 'send_count', type: Types::INTEGER)]
    private int $sendCount = 0;

    #[ORM\Column(name: 'invited_by', type: Types::STRING, length: self::IDENTIFIER_MAX_LENGTH, nullable: true)]
    private ?string $invitedBy;

    #[ORM\Column(name: 'accepted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'accepted_by', type: Types::STRING, length: self::IDENTIFIER_MAX_LENGTH, nullable: true)]
    private ?string $acceptedBy = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'revoked_by', type: Types::STRING, length: self::IDENTIFIER_MAX_LENGTH, nullable: true)]
    private ?string $revokedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @param string|null $invitedBy security identifier of the inviter (null: issued by a command or an anonymous demo)
     */
    public function __construct(string $profile, string $email, ?string $invitedBy, int $ttlDays, DateTimeImmutable $now)
    {
        $this->publicId  = bin2hex(random_bytes(16));
        $this->profile   = $profile;
        $this->email     = self::normalizeEmail($email);
        $this->invitedBy = $this->identifier($invitedBy);
        $this->createdAt = $now;
        $this->sentAt    = $now;
        $this->schedule($ttlDays, $now);
    }

    /** Lower-cased, trimmed e-mail (one open invitation per profile and e-mail). */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
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

    public function getEmail(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->payload = $payload;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $message = trim((string) $message);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->message = $message === '' ? null : mb_substr($message, 0, self::MESSAGE_MAX_LENGTH);

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->locale = $locale === '' ? null : $locale;

        return $this;
    }

    /**
     * SHA-256 of the current link token. Internal: never hand it to templates, events or logs.
     */
    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTtlDays(): int
    {
        return $this->ttlDays;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getSentAt(): DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getSendCount(): int
    {
        return $this->sendCount;
    }

    public function getInvitedBy(): ?string
    {
        return $this->invitedBy;
    }

    /**
     * A new link: the previous token stops matching at once, the expiry restarts from now.
     */
    public function issue(string $tokenHash, int $ttlDays, DateTimeImmutable $now): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->tokenHash = $tokenHash;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->sentAt = $now;
        $this->schedule($ttlDays, $now);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        ++$this->sendCount;
    }

    public function getAcceptedAt(): ?DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getAcceptedBy(): ?string
    {
        return $this->acceptedBy;
    }

    public function markAccepted(string $userIdentifier, DateTimeImmutable $now): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->acceptedBy = $this->identifier($userIdentifier);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->acceptedAt = $now;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevokedBy(): ?string
    {
        return $this->revokedBy;
    }

    public function revoke(?string $by, DateTimeImmutable $now): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->revokedBy = $this->identifier($by);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->revokedAt = $now;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Revoked, accepted, expired (the date passed) or pending. */
    public function status(DateTimeImmutable $now): InvitationStatus
    {
        return match (true) {
            $this->revokedAt instanceof DateTimeImmutable  => InvitationStatus::Revoked,
            $this->acceptedAt instanceof DateTimeImmutable => InvitationStatus::Accepted,
            $this->expiresAt <= $now                       => InvitationStatus::Expired,
            default                                        => InvitationStatus::Pending,
        };
    }

    /** Resend and revoke apply to pending and expired invitations only. */
    public function isOpen(): bool
    {
        return !$this->revokedAt instanceof DateTimeImmutable && !$this->acceptedAt instanceof DateTimeImmutable;
    }

    private function schedule(int $ttlDays, DateTimeImmutable $from): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->ttlDays = $ttlDays;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->expiresAt = $from->modify(sprintf('+%d days', $ttlDays));
    }

    private function identifier(?string $identifier): ?string
    {
        return $identifier === null || $identifier === '' ? null : mb_substr($identifier, 0, self::IDENTIFIER_MAX_LENGTH);
    }
}
