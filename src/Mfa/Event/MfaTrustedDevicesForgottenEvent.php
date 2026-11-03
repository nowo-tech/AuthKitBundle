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
}
