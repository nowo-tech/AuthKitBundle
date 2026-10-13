<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Symfony\Component\HttpFoundation\RequestStack;

use function is_array;
use function is_string;

/**
 * CSRF state (and the optional PKCE code_verifier) for the OAuth authorization-code redirect round-trip.
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
     */
    public function issue(string $provider, ?string $codeVerifier = null): string
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
        // @igor-ignore - Not shared worker service state.
        $session->set(self::SESSION_KEY, $payload);

        return $state;
    }

    public function consume(string $provider, string $state): bool
    {
        return $this->consumePending($provider, $state) !== null;
    }

    /**
     * Burns the pending state (single use) and returns its data when provider and state match.
     *
     * @return array{code_verifier: ?string}|null null when there is no matching pending authorization
     */
    public function consumePending(string $provider, string $state): ?array
    {
        $session = $this->requestStack->getSession();
        /** @var array{provider?: string, state?: string, code_verifier?: string}|null $payload */
        $payload = $session->get(self::SESSION_KEY);
        // @igor-ignore - Not shared worker service state.
        $session->remove(self::SESSION_KEY);

        if (!is_array($payload)) {
            return null;
        }

        $valid = ($payload['provider'] ?? null) === $provider
            && is_string($payload['state'] ?? null)
            && hash_equals($payload['state'], $state);
        if (!$valid) {
            return null;
        }

        $verifier = $payload['code_verifier'] ?? null;

        return ['code_verifier' => is_string($verifier) && $verifier !== '' ? $verifier : null];
    }
}
