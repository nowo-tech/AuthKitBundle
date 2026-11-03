<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base of the MFA audit events (1.34): the profile and the user concerned. Events never carry a secret, a code, an
 * otpauth URI or a token.
 */
abstract class MfaEvent extends Event
{
    public function __construct(
        public readonly string $profile,
        public readonly UserInterface $user,
    ) {
    }
}
