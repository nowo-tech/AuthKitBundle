<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A sign-in that waited for its second step is now complete: the final token is stored and the session id migrated.
 * Host `LoginSuccessEvent` listeners that must not run for a half-authenticated user listen to this event instead
 * (or skip `LoginSuccessEvent` while its token is an `MfaPendingToken`).
 */
final class MfaLoginCompletedEvent extends MfaEvent
{
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly TokenInterface $token,
        public readonly Request $request,
        public readonly string $firstFactor,
    ) {
        parent::__construct($profile, $user);
    }
}
