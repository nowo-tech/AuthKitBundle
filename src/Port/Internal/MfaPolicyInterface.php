<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port\Internal;

use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\MfaRequirementPolicyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0: AuthKit's internal view of the host port {@see MfaRequirementPolicyInterface}: what the
 * AuthKit services call, served by an internal gate over the host's (or the built-in) implementation of that port. It
 * replaces the public `Nowo\AuthKitBundle\Mfa\MfaPolicyInterface` (deprecated in 1.46.0, removed in 2.0). Never
 * implement it: implement the port.
 *
 * @internal
 */
interface MfaPolicyInterface
{
    public function requirement(UserInterface $user, string $profile): MfaRequirement;
}
