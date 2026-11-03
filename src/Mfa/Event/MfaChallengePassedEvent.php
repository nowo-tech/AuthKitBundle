<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A pending sign-in passed its second step. `method` is `totp`, `recovery` or `trusted_device`; `firstFactor` the
 * first factor key (`form_login`, `login_link`, `social`, …).
 */
final class MfaChallengePassedEvent extends MfaEvent
{
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly string $method,
        public readonly string $firstFactor,
    ) {
        parent::__construct($profile, $user);
    }
}
