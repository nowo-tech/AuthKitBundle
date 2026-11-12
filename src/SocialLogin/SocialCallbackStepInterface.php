<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;

/**
 * Takes over the OAuth callback when the host has its own pending step (1.46.0): the framework-free twin of the
 * deprecated {@see SocialCallbackHandlerInterface} (REQ-ARCH-001 rule 3). Autoconfigured with the same tag
 * (`nowo_auth_kit.social_callback_handler`, `priority` orders several handlers); the first that supports the callback
 * owns it (a class cannot implement both interfaces: their methods share names).
 *
 * Same guarantees as the deprecated interface: AuthKit verifies the OAuth state, PKCE and the code exchange before
 * {@see self::handle()} runs, a sign-in that fails afterwards is rolled back, and the first factor is marked `social`.
 * Instead of signing in and building a response yourself, return a {@see SocialCallbackResult}: AuthKit signs the user
 * in on the profile's firewall (MFA applies) and redirects. Keep pending data in the session
 * ({@see AuthRequestContext::sessionGet()} / {@see AuthRequestContext::sessionRemove()}); keep the service stateless.
 */
interface SocialCallbackStepInterface
{
    /**
     * Whether this step owns the callback. Runs first, on every callback, before any check and without
     * authentication: decide from server-side state (the session), never from the query string.
     */
    public function supports(AuthRequestContext $request, string $provider): bool;

    /**
     * The verified callback. Anything thrown signs out a user AuthKit signed in for it and takes the failure path
     * ({@see SocialCallbackFailure::Failed}).
     */
    public function handle(AuthRequestContext $request, SocialCallbackContext $context): SocialCallbackResult;

    /**
     * The callback proved its OAuth state and then failed. Null keeps the default answer (flash and redirect to the
     * sign-in page).
     */
    public function handleFailure(AuthRequestContext $request, string $provider, SocialCallbackFailure $reason): ?SocialCallbackResult;
}
