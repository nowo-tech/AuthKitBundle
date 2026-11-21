<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A recovery code was used (it cannot be used again); `remaining` unused codes are left.
 */
final class MfaRecoveryCodeUsedEvent extends MfaEvent
{
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly int $remaining,
    ) {
        parent::__construct($profile, $user);
    }

    /**
     * The `$remaining` value (1.55.0): read it here; same value as the property.
     */
    public function remaining(): int
    {
        return $this->remaining;
    }
}
