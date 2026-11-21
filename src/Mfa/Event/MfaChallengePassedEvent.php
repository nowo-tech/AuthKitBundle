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

    /**
     * The `$method` value (1.55.0): read it here; same value as the property.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * The `$firstFactor` value (1.55.0): read it here; same value as the property.
     */
    public function firstFactor(): string
    {
        return $this->firstFactor;
    }
}
