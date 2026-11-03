<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Whether a sign-in is complete enough to join the account switcher (1.34, `multi_account.enrolment_gate`). The
 * default ({@see DefaultEnrolmentGate}) waits for AuthKit's second factor; hosts with their own MFA implement this.
 */
interface EnrolmentGateInterface
{
    public function isComplete(TokenInterface $token): bool;
}
