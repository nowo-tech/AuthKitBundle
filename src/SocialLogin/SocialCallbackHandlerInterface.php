<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes over the OAuth callback when the host has its own pending step, such as accepting an invitation (1.37). The
 * tag `nowo_auth_kit.social_callback_handler` is added by autoconfiguration; a `priority` attribute orders several
 * handlers and the first whose {@see self::supports()} is true owns the callback.
 *
 * AuthKit still verifies the callback. {@see self::handle()} runs only after the OAuth state, PKCE and the code exchange
 * succeeded, in place of the default link-and-sign-in, so a handler never parses `code` or `state` and cannot skip them.
 * It does replace {@see SocialAccountLinker}: call the linker from the handler when you can, because the identity rules
 * live there; otherwise they are the handler's job. See docs/SOCIAL-LOGIN.md.
 *
 * Implementations are shared services: keep them stateless (worker safe) and keep pending data in the session.
 *
 * @deprecated since 1.46.0, implement {@see SocialCallbackStepInterface} (framework-free, REQ-ARCH-001 rule 3) instead; removed in 2.0.
 */
interface SocialCallbackHandlerInterface
{
    /**
     * Whether this handler owns the callback. Host code that runs first, on every callback, before any check and
     * without authentication: keep it cheap and decide from server-side state (the session), never from the query
     * string. Compare the provider with the one stored in your pending entry. If it throws, the visitor gets a 500.
     */
    public function supports(Request $request, string $provider): bool;

    /**
     * The verified callback. Sign the user in last, with `Security::login()`, and return its response if it has one (the
     * MFA challenge); otherwise answer with a redirect, so the URL holding `code` and `state` leaves the address bar. The
     * first factor is already marked `social`, so MFA applies as in the default flow.
     *
     * Anything thrown here signs out a user the handler had signed in, and is logged by class. An HTTP exception is
     * rethrown; anything else becomes {@see SocialCallbackFailure::Failed}.
     */
    public function handle(Request $request, SocialCallbackContext $context): Response;

    /**
     * The callback proved its OAuth state and then failed. A callback that does not prove it never gets here (a crafted
     * link cannot drop a pending step), so expire an abandoned pending entry yourself. Null keeps the default answer
     * (flash and redirect to the sign-in page).
     */
    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response;
}
