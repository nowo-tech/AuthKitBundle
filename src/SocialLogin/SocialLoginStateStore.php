<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Symfony\Component\HttpFoundation\RequestStack;

use function is_array;
use function is_string;

/**
 * CSRF state for the OAuth authorization-code redirect round-trip.
 */
final class SocialLoginStateStore
{
    private const SESSION_KEY = '_nowo_auth_kit_social_oauth_state';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function issue(string $provider): string
    {
        $state   = bin2hex(random_bytes(16));
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, [
            'provider' => $provider,
            'state'    => $state,
        ]);

        return $state;
    }

    public function consume(string $provider, string $state): bool
    {
        $session = $this->requestStack->getSession();
        /** @var array{provider?: string, state?: string}|null $payload */
        $payload = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        if (!is_array($payload)) {
            return false;
        }

        return ($payload['provider'] ?? null) === $provider
            && is_string($payload['state'] ?? null)
            && hash_equals($payload['state'], $state);
    }
}
