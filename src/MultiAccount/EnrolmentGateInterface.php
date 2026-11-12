<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Whether a sign-in is complete enough to join the account switcher (1.34, `multi_account.enrolment_gate`). The
 * default ({@see DefaultEnrolmentGate}) waits for AuthKit's second factor; hosts with their own MFA implement this.
 *
 * @deprecated since 1.46.0, implement {@see EnrolmentPolicyInterface} (framework-free, REQ-ARCH-001 rule 3) instead; removed in 2.0.
 */
interface EnrolmentGateInterface
{
    public function isComplete(TokenInterface $token): bool;
}
