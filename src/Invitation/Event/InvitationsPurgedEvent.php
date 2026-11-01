<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;

/**
 * Dispatched by `nowo:auth-kit:invitations:purge` after deleting closed invitations (not on a dry run).
 */
final class InvitationsPurgedEvent
{
    public function __construct(
        public readonly int $count,
        public readonly DateTimeImmutable $before,
        public readonly ?string $profile,
        public readonly bool $includeAccepted,
    ) {
    }
}
