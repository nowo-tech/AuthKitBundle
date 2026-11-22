<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port\Internal;

use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2.0: AuthKit's internal view of the host port {@see SocialCallbackStepInterface}:
 * what the AuthKit services call, served by an internal gate over the host's (or the built-in) implementation of that
 * port. It replaces the public `Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface` (deprecated in 1.46.0,
 * removed in 2.0). Never implement it: implement the port.
 *
 * @internal
 */
interface SocialCallbackHandlerInterface
{
    public function supports(Request $request, string $provider): bool;

    public function handle(Request $request, SocialCallbackContext $context): Response;

    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response;
}
