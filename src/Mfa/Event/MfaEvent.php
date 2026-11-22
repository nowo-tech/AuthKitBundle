<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base of the MFA audit events (1.34): the profile and the user concerned. Events never carry a secret, a code, an
 * otpauth URI or a token.
 */
abstract class MfaEvent extends Event
{
    /**
     * @param UserInterface $user not public since 2.0: read userRef()
     */
    public function __construct(
        public readonly string $profile,
        private readonly UserInterface $user,
    ) {
    }

    /**
     * The user, framework-free (1.46.0, REQ-ARCH-001 rule 3).
     */
    public function userRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->user);
    }

    /**
     * The `$profile` value (1.55.0): read it here; same value as the property.
     */
    public function profile(): string
    {
        return $this->profile;
    }
}
