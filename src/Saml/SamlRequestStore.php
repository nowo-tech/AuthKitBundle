<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function bin2hex;
use function hash;
use function hash_equals;
use function is_array;
use function is_int;
use function is_string;
use function random_bytes;

/**
 * The pending AuthnRequest of a browser (2.10.0): request id, RelayState, provider, profile, expiry and the test marker,
 * kept in the visitor's session (one at a time: a new start replaces it, as an OAuth start does).
 *
 * Single use: {@see self::consume()} removes it from the session and claims the request id once in the attempt
 * limiter's store under its per-key lock, so two parallel requests of one session (session handlers that do not lock)
 * cannot both use it. A RelayState that does not match leaves the pending request alone: a crafted POST cannot cancel
 * the visitor's real sign-in. Worker-safe: everything lives in the session and the cache pool (REQ-RUNTIME-003).
 *
 * @internal
 */
final class SamlRequestStore
{
    public const SESSION_KEY = '_nowo_auth_kit_saml_request';

    public function __construct(
        private readonly AuthKitAttemptLimiter $limiter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * A new random RelayState (128 bits, hex).
     */
    public static function newRelayState(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Stores the pending request (replacing any other of this session).
     */
    public function issue(SessionInterface $session, string $profile, string $provider, string $requestId, string $relayState, int $lifetime, bool $test = false): void
    {
        // @igor-ignore - The request's own session (not an injected service).
        $session->set(self::SESSION_KEY, [
            'profile'     => $profile,
            'provider'    => $provider,
            'request_id'  => $requestId,
            'relay_state' => $relayState,
            'expires'     => $this->clock->now()->getTimestamp() + $lifetime,
            'lifetime'    => $lifetime,
            'test'        => $test,
        ]);
    }

    /**
     * Whether the matching pending request carries the test marker (reads only).
     */
    public function isTestPending(SessionInterface $session, string $profile, string $provider, string $relayState): bool
    {
        return ($this->matching($session, $profile, $provider, $relayState)['test'] ?? false) === true;
    }

    /**
     * Burns the pending request and returns its request id and test marker.
     *
     * @throws SamlFailure `unsolicited` (none for this provider), `invalid_state` (RelayState mismatch: left untouched),
     *                     `expired_request`, `replayed`
     *
     * @return array{request_id: string, test: bool}
     */
    public function consume(SessionInterface $session, string $profile, string $provider, string $relayState): array
    {
        $payload = $this->payload($session);
        if ($payload === null || $payload['profile'] !== $profile || $payload['provider'] !== $provider) {
            throw new SamlFailure(SamlFailure::UNSOLICITED, 'No SAML sign-in of this session is pending for this provider.');
        }
        if ($relayState === '' || !hash_equals($payload['relay_state'], $relayState)) {
            throw new SamlFailure(SamlFailure::INVALID_STATE, 'The RelayState does not match the pending SAML sign-in.');
        }
        // @igor-ignore - The request's own session (not an injected service).
        $session->remove(self::SESSION_KEY);
        if ($payload['expires'] <= $this->clock->now()->getTimestamp()) {
            throw new SamlFailure(SamlFailure::EXPIRED_REQUEST, 'The SAML sign-in took too long at the identity provider.');
        }
        if (!$this->limiter->consume('saml:request:' . hash('sha256', $payload['request_id']), 1, $payload['lifetime'] + 60)) {
            throw new SamlFailure(SamlFailure::REPLAYED, 'The SAML request was already used.');
        }

        return ['request_id' => $payload['request_id'], 'test' => $payload['test']];
    }

    /**
     * @return array{profile: string, provider: string, request_id: string, relay_state: string, expires: int, lifetime: int, test: bool}|null
     */
    private function matching(SessionInterface $session, string $profile, string $provider, string $relayState): ?array
    {
        $payload = $this->payload($session);

        return $payload !== null && $relayState !== '' && $payload['profile'] === $profile && $payload['provider'] === $provider && hash_equals($payload['relay_state'], $relayState) ? $payload : null;
    }

    /**
     * @return array{profile: string, provider: string, request_id: string, relay_state: string, expires: int, lifetime: int, test: bool}|null
     */
    private function payload(SessionInterface $session): ?array
    {
        $payload = $session->get(self::SESSION_KEY);
        if (!is_array($payload)) {
            return null;
        }
        foreach (['profile', 'provider', 'request_id', 'relay_state'] as $key) {
            if (!is_string($payload[$key] ?? null) || $payload[$key] === '') {
                return null;
            }
        }
        if (!is_int($payload['expires'] ?? null) || !is_int($payload['lifetime'] ?? null)) {
            return null;
        }

        return [
            'profile'     => $payload['profile'],
            'provider'    => $payload['provider'],
            'request_id'  => $payload['request_id'],
            'relay_state' => $payload['relay_state'],
            'expires'     => $payload['expires'],
            'lifetime'    => $payload['lifetime'],
            'test'        => ($payload['test'] ?? false) === true,
        ];
    }
}
