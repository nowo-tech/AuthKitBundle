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

    /**
     * The `$count` value (1.55.0): read it here; same value as the property.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * The `$before` value (1.55.0): read it here; same value as the property.
     */
    public function before(): DateTimeImmutable
    {
        return $this->before;
    }

    /**
     * The `$profile` value (1.55.0): read it here; same value as the property.
     */
    public function profile(): ?string
    {
        return $this->profile;
    }

    /**
     * The `$includeAccepted` value (1.55.0): read it here; same value as the property.
     */
    public function includeAccepted(): bool
    {
        return $this->includeAccepted;
    }
}
