<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\ValueObject\EnrolmentCandidate;

/**
 * Whether a sign-in is complete enough to join the account switcher (1.46.0, `multi_account.enrolment_gate`): the
 * framework-free port (REQ-ARCH-001 rule 3; 2.0: replaces the removed `EnrolmentGateInterface`), with an
 * {@see EnrolmentCandidate} instead of the security token.
 */
interface EnrolmentPolicyInterface
{
    public function allowsEnrolment(EnrolmentCandidate $candidate): bool;
}
