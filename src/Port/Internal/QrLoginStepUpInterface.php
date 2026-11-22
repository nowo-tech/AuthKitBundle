<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port\Internal;

use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * 2.0: AuthKit's internal view of the host port {@see QrLoginStepUpPolicyInterface}: what
 * the AuthKit services call, served by an internal gate over the host's (or the built-in) implementation of that port.
 * It replaces the public `Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface` (deprecated in 1.46.0, removed in 2.0).
 * Never implement it: implement the port.
 *
 * @internal
 */
interface QrLoginStepUpInterface
{
    /**
     * @throws AccessDeniedException when unlock fails or is cancelled
     */
    public function assertUnlocked(Request $request): void;
}
