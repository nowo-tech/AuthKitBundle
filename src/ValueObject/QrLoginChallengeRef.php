<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\ValueObject;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;

/**
 * Framework-free snapshot of a QR login challenge for the QR events (1.46.0, REQ-ARCH-001 rules 1 and 4), instead of
 * the {@see QrLoginChallenge} entity. Holds no secret: no cookie, IP, user-agent or approve-token hash.
 */
final readonly class QrLoginChallengeRef
{
    public function __construct(
        public string $id,
        public string $publicCode,
        public QrLoginChallengeStatus $status,
        public ?string $userClass,
        public ?string $userId,
        public string $desktopUaLabel,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $approvedAt,
        public ?DateTimeImmutable $consumedAt,
    ) {
    }

    /**
     * The snapshot of a challenge as it is when the event is dispatched.
     */
    public static function fromChallenge(QrLoginChallenge $challenge): self
    {
        return new self(
            $challenge->getId(),
            $challenge->getPublicCode(),
            $challenge->getStatus(),
            $challenge->getUserClass(),
            $challenge->getUserId(),
            $challenge->getDesktopUaLabel(),
            $challenge->getExpiresAt(),
            $challenge->getCreatedAt(),
            $challenge->getApprovedAt(),
            $challenge->getConsumedAt(),
        );
    }
}
