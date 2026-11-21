<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A wrong (or replayed) code was entered. `kind` is `totp` or `recovery`; `context` is `challenge` (sign-in) or
 * `step_up` (account page). The code itself is never part of the event.
 */
final class MfaChallengeFailedEvent extends MfaEvent
{
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly string $kind,
        public readonly string $context,
    ) {
        parent::__construct($profile, $user);
    }

    /**
     * The `$kind` value (1.55.0): read it here; same value as the property.
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * The `$context` value (1.55.0): read it here; same value as the property.
     */
    public function context(): string
    {
        return $this->context;
    }
}
