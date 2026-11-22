<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\MfaRequirementPolicyInterface;
use Nowo\AuthKitBundle\Port\Internal\MfaPolicyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see MfaRequirementPolicyInterface} (1.46.0) where the bundle asks its internal
 * {@see MfaPolicyInterface}. Exceptions propagate. Built per call by {@see Ports} (2.0: the only way AuthKit asks the port).
 *
 * @internal
 */
final readonly class MfaRequirementPolicyGate implements MfaPolicyInterface
{
    public function __construct(private MfaRequirementPolicyInterface $policy)
    {
    }

    public function requirement(UserInterface $user, string $profile): MfaRequirement
    {
        return $this->policy->requirementFor(AuthUserRefs::fromUser($user), $profile);
    }
}
