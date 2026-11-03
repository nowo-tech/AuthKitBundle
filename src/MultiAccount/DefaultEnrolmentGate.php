<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default enrolment gate (1.34): a token with a user, not waiting for AuthKit's second factor (D-M8: native MFA only)
 * and not an impersonation.
 */
final class DefaultEnrolmentGate implements EnrolmentGateInterface
{
    public function isComplete(TokenInterface $token): bool
    {
        return $token->getUser() instanceof UserInterface && !$token instanceof MfaPendingToken && !$token instanceof SwitchUserToken;
    }
}
