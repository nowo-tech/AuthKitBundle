<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Host rule deciding whether a user must have a second factor (1.46.0, `mfa.policy`): the framework-free twin of the
 * deprecated {@see MfaPolicyInterface} (REQ-ARCH-001 rule 3). Asked after `mfa.required_roles` (a role requirement
 * always wins), at sign-in and on the account page only. A service implementing both interfaces is asked through the
 * deprecated one until 2.0.
 */
interface MfaRequirementPolicyInterface
{
    public function requirementFor(AuthUserRef $user, string $profile): MfaRequirement;
}
