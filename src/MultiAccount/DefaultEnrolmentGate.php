<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\EnrolmentCandidate;

/**
 * Default enrolment policy (1.34): a token with a user, not waiting for AuthKit's second factor (D-M8: native MFA only)
 * and not an impersonation.
 *
 * 2.0: on the port {@see EnrolmentPolicyInterface} (it implemented the removed EnrolmentGateInterface on the token;
 * the candidate carries the same three facts, so the answer is the same).
 */
final class DefaultEnrolmentGate implements EnrolmentPolicyInterface
{
    public function allowsEnrolment(EnrolmentCandidate $candidate): bool
    {
        return $candidate->user instanceof AuthUserRef && !$candidate->secondFactorPending && !$candidate->impersonated;
    }
}
