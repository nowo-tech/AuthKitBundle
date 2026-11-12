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
     * @param UserInterface $user @deprecated since 1.46.0, use userRef(); removed in 2.0
     */
    public function __construct(
        public readonly string $profile,
        public readonly UserInterface $user,
    ) {
    }

    /**
     * The user, framework-free (1.46.0, REQ-ARCH-001 rule 3).
     */
    public function userRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->user);
    }
}
