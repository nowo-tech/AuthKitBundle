<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Symfony\Component\HttpFoundation\RequestStack;

use function is_array;
use function is_string;

/**
 * CSRF state (and the optional PKCE code_verifier and OIDC nonce) for the OAuth authorization-code redirect round-trip.
 */
final class SocialLoginStateStore
{
    private const SESSION_KEY = '_nowo_auth_kit_social_oauth_state';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param string|null $codeVerifier PKCE verifier to keep server-side until the callback
     * @param string|null $nonce OpenID Connect nonce sent with the request, checked against the ID token (2.3.0)
     * @param bool $test 2.7.0: one-shot test marker bound to this state ({@see SocialLoginTestStart}); the callback of
     *                   this state then only reaches the host's test handler and never signs in, links or provisions
     */
    public function issue(string $provider, ?string $codeVerifier = null, ?string $nonce = null, bool $test = false): string
    {
        $state   = bin2hex(random_bytes(16));
        $session = $this->requestStack->getSession();
        $payload = [
            'provider' => $provider,
            'state'    => $state,
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $payload['code_verifier'] = $codeVerifier;
        }
        if ($nonce !== null && $nonce !== '') {
            $payload['nonce'] = $nonce;
        }
        if ($test) {
            $payload['test'] = true;
        }
        // @igor-ignore - Not shared worker service state.
        $session->set(self::SESSION_KEY, $payload);

        return $state;
    }

    public function consume(string $provider, string $state): bool
    {
        return $this->consumePending($provider, $state) !== null;
    }

    /**
     * Whether the pending authorization of this provider and state carries the test marker (2.7.0). Reads only: the
     * state is burned later by {@see self::consumePending()}. A state that does not match is not a test.
     */
    public function isTestPending(string $provider, string $state): bool
    {
        // No state, no lookup (and no session started for a request that carries none).
        if ($state === '') {
            return false;
        }
        $payload = $this->matching($provider, $state);

        return $payload !== null && ($payload['test'] ?? false) === true;
    }

    /**
     * Burns the pending state (single use) and returns its data when provider and state match. A request that does not
     * match leaves it alone (GY-L2, 1.37): a crafted callback link must not cancel the visitor's real authorization.
     *
     * @return array{code_verifier: ?string, nonce: ?string, test: bool}|null null when there is no matching pending
     *                                                                        authorization; `test` (2.7.0) is the marker
     */
    public function consumePending(string $provider, string $state): ?array
    {
        $payload = $this->matching($provider, $state);
        if ($payload === null) {
            return null;
        }

        // @igor-ignore - Not shared worker service state.
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
        $verifier = $payload['code_verifier'] ?? null;
        $nonce    = $payload['nonce'] ?? null;

        return [
            'code_verifier' => is_string($verifier) && $verifier !== '' ? $verifier : null,
            'nonce'         => is_string($nonce) && $nonce !== '' ? $nonce : null,
            'test'          => ($payload['test'] ?? false) === true,
        ];
    }

    /**
     * @return array<string, mixed>|null the pending payload when provider and state match
     */
    private function matching(string $provider, string $state): ?array
    {
        $payload = $this->requestStack->getSession()->get(self::SESSION_KEY);
        if (!is_array($payload)) {
            return null;
        }

        $valid = ($payload['provider'] ?? null) === $provider
            && is_string($payload['state'] ?? null)
            && hash_equals($payload['state'], $state);

        return $valid ? $payload : null;
    }
}
