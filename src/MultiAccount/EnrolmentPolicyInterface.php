<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\ValueObject\EnrolmentCandidate;

/**
 * Whether a sign-in is complete enough to join the account switcher (1.46.0, `multi_account.enrolment_gate`): the
 * framework-free twin of the deprecated {@see EnrolmentGateInterface} (REQ-ARCH-001 rule 3), with an
 * {@see EnrolmentCandidate} instead of the security token. A service implementing both interfaces is asked through the
 * deprecated one until 2.0.
 */
interface EnrolmentPolicyInterface
{
    public function allowsEnrolment(EnrolmentCandidate $candidate): bool;
}
