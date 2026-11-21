<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Trusted devices were forgotten. `reason`: `user` (account page), `limit` (over the maximum), `disabled`, `reset`,
 * `re_enrolled` or `sessions` (UserKit sign-out everywhere, password change, account disabled). `count` devices.
 */
final class MfaTrustedDevicesForgottenEvent extends MfaEvent
{
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly string $reason,
        public readonly int $count,
    ) {
        parent::__construct($profile, $user);
    }

    /**
     * The `$reason` value (1.55.0): read it here; same value as the property.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The `$count` value (1.55.0): read it here; same value as the property.
     */
    public function count(): int
    {
        return $this->count;
    }
}
