<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use JsonException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function base64_decode;
use function base64_encode;
use function function_exists;
use function hash_hkdf;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sodium_crypto_aead_xchacha20poly1305_ietf_decrypt;
use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

/**
 * The RP-initiated logout hint of the session (2.8.0), sealed with XChaCha20-Poly1305 under a key derived (HKDF-SHA256)
 * from `kernel.secret`: the session store (files, Redis, database) never holds the ID token or the user in clear, and
 * a value copied from another application or tampered with does not open.
 *
 * Bounded: one hint per session (a new sign-in replaces it), an ID token larger than {@see self::MAX_ID_TOKEN_BYTES}
 * is not kept (the logout then sends `client_id` only), and a hint whose other parts exceed their bounds is not kept
 * at all. Never logged. Stateless (the session is the only memory): safe in FrankenPHP worker mode.
 */
final readonly class RpLogoutSession
{
    public const SESSION_KEY = '_nowo_auth_kit_rp_logout';

    /** Largest ID token kept for `id_token_hint` (Entra ID tokens with group claims stay well below). */
    public const MAX_ID_TOKEN_BYTES = 8192;

    /** Longest user identifier kept (longer ones: no hint, local sign-out only). */
    public const MAX_USER_IDENTIFIER_BYTES = 1024;

    private const ASSOCIATED_DATA = 'nowo-auth-kit-rp-logout|v1';

    public function __construct(
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * Keeps the hint in the session, replacing any previous one; a hint out of bounds removes the previous one only.
     */
    public function remember(SessionInterface $session, RpLogoutHint $hint): void
    {
        $session->remove(self::SESSION_KEY);
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            return; // @codeCoverageIgnore
        }
        if ($hint->provider === '' || strlen($hint->provider) > 64
            || $hint->clientId === '' || strlen($hint->clientId) > 255
            || $hint->userIdentifier === '' || strlen($hint->userIdentifier) > self::MAX_USER_IDENTIFIER_BYTES
            || $hint->endSessionEndpoint === '' || strlen($hint->endSessionEndpoint) > OidcDiscovery::MAX_OPTIONAL_ENDPOINT_LENGTH) {
            return;
        }
        $idToken = $hint->idToken !== null && $hint->idToken !== '' && strlen($hint->idToken) <= self::MAX_ID_TOKEN_BYTES ? $hint->idToken : null;

        $nonce   = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $payload = json_encode(['p' => $hint->provider, 'c' => $hint->clientId, 'u' => $hint->userIdentifier, 'e' => $hint->endSessionEndpoint, 't' => $idToken], JSON_THROW_ON_ERROR);
        $session->set(self::SESSION_KEY, base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, self::ASSOCIATED_DATA, $nonce, $this->key())));
    }

    /**
     * Removes the hint from the session (a sign-in without RP-initiated logout).
     */
    public function forget(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    /**
     * Removes the hint from the session and returns it, or null when there is none or it does not open.
     */
    public function take(SessionInterface $session): ?RpLogoutHint
    {
        $sealed = $session->remove(self::SESSION_KEY);
        if (!is_string($sealed) || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            return null;
        }
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), self::ASSOCIATED_DATA, substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), $this->key());
        if ($plain === false) {
            return null;
        }

        try {
            $data = json_decode($plain, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !is_string($data['p'] ?? null) || !is_string($data['c'] ?? null) || !is_string($data['u'] ?? null) || !is_string($data['e'] ?? null)) {
            return null;
        }
        $idToken = $data['t'] ?? null;

        return new RpLogoutHint($data['p'], $data['c'], $data['u'], $data['e'], is_string($idToken) ? $idToken : null);
    }

    private function key(): string
    {
        // Prefixed: HKDF refuses empty input keying material, and an empty kernel.secret must not break a sign-out.
        return hash_hkdf('sha256', 'nowo-auth-kit-rp-logout|' . $this->secret, 32, 'nowo-auth-kit-rp-logout-seal');
    }
}
