<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Repository\QrLoginChallengeRepository;

/**
 * Bundle-owned challenge entity for QR phone login.
 */
#[ORM\Entity(repositoryClass: QrLoginChallengeRepository::class)]
#[ORM\Table(name: 'auth_kit_qr_login_challenge')]
#[ORM\Index(name: 'idx_qr_challenge_status_expires', columns: ['status', 'expires_at'])]
class QrLoginChallenge
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(name: 'public_code', type: Types::STRING, length: 8, unique: true)]
    private string $publicCode;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status;

    #[ORM\Column(name: 'user_class', type: Types::STRING, length: 255, nullable: true)]
    private ?string $userClass = null;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(name: 'phone_hint', type: Types::STRING, length: 32, nullable: true)]
    private ?string $phoneHint = null;

    #[ORM\Column(name: 'desktop_cookie_hash', type: Types::STRING, length: 64)]
    private string $desktopCookieHash;

    #[ORM\Column(name: 'desktop_ip_hash', type: Types::STRING, length: 64)]
    private string $desktopIpHash;

    #[ORM\Column(name: 'desktop_ua_hash', type: Types::STRING, length: 64)]
    private string $desktopUaHash;

    #[ORM\Column(name: 'desktop_ua_label', type: Types::STRING, length: 128)]
    private string $desktopUaLabel;

    #[ORM\Column(name: 'approve_token_hash', type: Types::STRING, length: 64)]
    private string $approveTokenHash;

    #[ORM\Column(name: 'approve_token_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $approveTokenUsedAt = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'approved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $approvedAt = null;

    #[ORM\Column(name: 'consumed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $publicCode,
        string $desktopCookieHash,
        string $desktopIpHash,
        string $desktopUaHash,
        string $desktopUaLabel,
        string $approveTokenHash,
        DateTimeImmutable $expiresAt,
    ) {
        $now = new DateTimeImmutable();

        $this->id                = $id;
        $this->publicCode        = $publicCode;
        $this->status            = QrLoginChallengeStatus::Pending->value;
        $this->desktopCookieHash = $desktopCookieHash;
        $this->desktopIpHash     = $desktopIpHash;
        $this->desktopUaHash     = $desktopUaHash;
        $this->desktopUaLabel    = $desktopUaLabel;
        $this->approveTokenHash  = $approveTokenHash;
        $this->expiresAt         = $expiresAt;
        $this->createdAt         = $now;
        $this->updatedAt         = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPublicCode(): string
    {
        return $this->publicCode;
    }

    public function getStatus(): QrLoginChallengeStatus
    {
        return QrLoginChallengeStatus::from($this->status);
    }

    public function setStatus(QrLoginChallengeStatus $status): self
    {
        $this->status = $status->value;
        $this->touch();

        return $this;
    }

    public function getUserClass(): ?string
    {
        return $this->userClass;
    }

    public function setUserClass(?string $userClass): self
    {
        $this->userClass = $userClass;
        $this->touch();

        return $this;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;
        $this->touch();

        return $this;
    }

    public function getPhoneHint(): ?string
    {
        return $this->phoneHint;
    }

    public function setPhoneHint(?string $phoneHint): self
    {
        $this->phoneHint = $phoneHint;
        $this->touch();

        return $this;
    }

    public function getDesktopCookieHash(): string
    {
        return $this->desktopCookieHash;
    }

    public function getDesktopIpHash(): string
    {
        return $this->desktopIpHash;
    }

    public function getDesktopUaHash(): string
    {
        return $this->desktopUaHash;
    }

    public function getDesktopUaLabel(): string
    {
        return $this->desktopUaLabel;
    }

    public function getApproveTokenHash(): string
    {
        return $this->approveTokenHash;
    }

    public function getApproveTokenUsedAt(): ?DateTimeImmutable
    {
        return $this->approveTokenUsedAt;
    }

    public function markApproveTokenUsed(): self
    {
        $this->approveTokenUsedAt = new DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return new DateTimeImmutable() > $this->expiresAt;
    }

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function markApproved(string $userClass, string $userId, ?string $phoneHint): self
    {
        $this->status     = QrLoginChallengeStatus::Approved->value;
        $this->userClass  = $userClass;
        $this->userId     = $userId;
        $this->phoneHint  = $phoneHint;
        $this->approvedAt = new DateTimeImmutable();
        $this->markApproveTokenUsed();

        return $this;
    }

    public function markDenied(): self
    {
        $this->status = QrLoginChallengeStatus::Denied->value;
        $this->markApproveTokenUsed();

        return $this;
    }

    public function markConsumed(): self
    {
        $this->status     = QrLoginChallengeStatus::Consumed->value;
        $this->consumedAt = new DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function markExpired(): self
    {
        $this->status = QrLoginChallengeStatus::Expired->value;
        $this->touch();

        return $this;
    }

    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
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
        $this->updatedAt = new DateTimeImmutable();
    }
}
