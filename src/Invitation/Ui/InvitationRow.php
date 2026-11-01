<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Ui;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\InvitationStatus;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;

use function implode;

/**
 * One row of the invitation list (plain values, no token or hash).
 */
final readonly class InvitationRow
{
    public function __construct(
        public string $publicId,
        public string $email,
        public string $roles,
        public ?string $invitedBy,
        public DateTimeImmutable $sentAt,
        public int $sendCount,
        public DateTimeImmutable $expiresAt,
        public InvitationStatus $status,
    ) {
    }

    public static function of(InvitationSnapshot $invitation): self
    {
        return new self(
            $invitation->publicId,
            $invitation->email,
            implode(', ', $invitation->roles),
            $invitation->invitedBy,
            $invitation->sentAt,
            $invitation->sendCount,
            $invitation->expiresAt,
            $invitation->status,
        );
    }
}
