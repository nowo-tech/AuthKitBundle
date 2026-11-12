<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host rule deciding whether a user must have a second factor (`mfa.policy`, 1.34), asked after
 * `mfa.required_roles` (a role requirement always wins). Called at sign-in and on the account page only.
 *
 * @deprecated since 1.46.0, implement {@see MfaRequirementPolicyInterface} (framework-free, REQ-ARCH-001 rule 3) instead; removed in 2.0.
 */
interface MfaPolicyInterface
{
    public function requirement(UserInterface $user, string $profile): MfaRequirement;
}
