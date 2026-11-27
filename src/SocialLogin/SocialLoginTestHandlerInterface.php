<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;

/**
 * The host's answer to a *test sign-in* (2.7.0): an administrator checks a provider credential without signing in.
 * A framework-free port (REQ-ARCH-001 rule 3), autoconfigured with the tag {@see self::TAG}; the first one (by
 * `priority`) is used.
 *
 * A test starts only through {@see SocialLoginTestStart::start()}, which binds a one-shot marker to the OAuth state in
 * the session (never a URL parameter). The callback of that state runs every check of a real sign-in (state, PKCE, code
 * exchange, ID token signature, `iss`, `aud`, `exp` / `iat`, `nonce`, userinfo subject) and then reaches this port only:
 * no {@see SocialCallbackStepInterface} runs, nothing is linked, no account is created and nobody is signed in.
 */
interface SocialLoginTestHandlerInterface
{
    public const TAG = 'nowo_auth_kit.social_test_handler';

    /**
     * The verified test callback. `$context->socialProfile->idTokenClaims` holds the validated ID token claims (`sub`,
     * `email`, `email_verified`, `amr`, `hd` / `tid` when present); null for an OAuth2 provider.
     *
     * Answer with {@see SocialCallbackResult::redirect()}: a {@see SocialCallbackResult::signIn()} result is refused (the
     * test takes the failure path and nobody is signed in). Anything thrown takes the failure path as well.
     */
    public function handle(AuthRequestContext $request, SocialCallbackContext $context): SocialCallbackResult;

    /**
     * The test callback proved its state and then failed (provider refusal, credential gone, token or claim check).
     * Null keeps the default answer (flash and redirect to the sign-in page); a sign-in result is ignored.
     */
    public function handleFailure(AuthRequestContext $request, string $provider, SocialCallbackFailure $reason): ?SocialCallbackResult;
}
