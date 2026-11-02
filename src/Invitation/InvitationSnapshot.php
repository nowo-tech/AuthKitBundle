<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Enum\InvitationStatus;

/**
 * Immutable copy of an invitation for events, mailers, templates and the {@see InvitationGrantInterface} services.
 * It never carries the token nor its hash.
 */
final readonly class InvitationSnapshot
{
    /**
     * @param list<string> $roles
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $publicId,
        public string $profile,
        public string $email,
        public array $roles,
        public array $payload,
        public ?string $message,
        public ?string $locale,
        public ?string $invitedBy,
        public int $ttlDays,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $sentAt,
        public int $sendCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?string $acceptedBy,
        public ?DateTimeImmutable $revokedAt,
        public ?string $revokedBy,
        public InvitationStatus $status,
        public ?string $lastSentBy = null,
    ) {
    }

    public static function of(Invitation $invitation, DateTimeImmutable $now): self
    {
        return new self(
            publicId: $invitation->getPublicId(),
            profile: $invitation->getProfile(),
            email: $invitation->getEmail(),
            roles: $invitation->getRoles(),
            payload: $invitation->getPayload(),
            message: $invitation->getMessage(),
            locale: $invitation->getLocale(),
            invitedBy: $invitation->getInvitedBy(),
            ttlDays: $invitation->getTtlDays(),
            expiresAt: $invitation->getExpiresAt(),
            sentAt: $invitation->getSentAt(),
            sendCount: $invitation->getSendCount(),
            createdAt: $invitation->getCreatedAt(),
            acceptedAt: $invitation->getAcceptedAt(),
            acceptedBy: $invitation->getAcceptedBy(),
            revokedAt: $invitation->getRevokedAt(),
            revokedBy: $invitation->getRevokedBy(),
            status: $invitation->status($now),
            lastSentBy: $invitation->getLastSentBy(),
        );
    }
}
