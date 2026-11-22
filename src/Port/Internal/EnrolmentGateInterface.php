<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port\Internal;

use Nowo\AuthKitBundle\MultiAccount\EnrolmentPolicyInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * 2.0: AuthKit's internal view of the host port {@see EnrolmentPolicyInterface}: what
 * the AuthKit services call, served by an internal gate over the host's (or the built-in) implementation of that port.
 * It replaces the public `Nowo\AuthKitBundle\MultiAccount\EnrolmentGateInterface` (deprecated in 1.46.0, removed in
 * 2.0). Never implement it: implement the port.
 *
 * @internal
 */
interface EnrolmentGateInterface
{
    public function isComplete(TokenInterface $token): bool;
}
