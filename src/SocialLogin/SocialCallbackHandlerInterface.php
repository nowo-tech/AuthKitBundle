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
 * It does replace {@see SocialAccountLinker}: `require_verified_email`, the stored-identifier match and the
 * `registration_mode` gate are then the handler's job. See docs/SOCIAL-LOGIN.md.
 *
 * Implementations are shared services: keep them stateless (worker safe) and keep pending data in the session.
 */
interface SocialCallbackHandlerInterface
{
    /**
     * Whether this handler owns the callback. Asked first on every callback, before any check, so an answer cannot
     * depend on the provider's data. Decide from server-side state (the session), never from the query string.
     */
    public function supports(Request $request, string $provider): bool;

    /**
     * The verified callback. Sign the user in with `Security::login()` if needed: the first factor is already marked
     * `social`, so MFA applies as in the default flow. A RuntimeException becomes {@see SocialCallbackFailure::Failed}.
     */
    public function handle(Request $request, SocialCallbackContext $context): Response;

    /**
     * The callback failed before {@see self::handle()} or inside it. Null keeps the default answer (flash and redirect
     * to the sign-in page). The request is unverified here, so clean up pending state and answer, and grant nothing.
     */
    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response;
}
