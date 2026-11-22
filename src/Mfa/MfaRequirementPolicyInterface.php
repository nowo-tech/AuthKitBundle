<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Host rule deciding whether a user must have a second factor (1.46.0, `mfa.policy`): a framework-free port
 * (REQ-ARCH-001 rule 3; 2.0: replaces the removed `MfaPolicyInterface`). Asked after `mfa.required_roles` (a role
 * requirement always wins), at sign-in and on the account page only.
 */
interface MfaRequirementPolicyInterface
{
    public function requirementFor(AuthUserRef $user, string $profile): MfaRequirement;
}
