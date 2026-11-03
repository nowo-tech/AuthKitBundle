<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use function base64_decode;
use function base64_encode;
use function hash_hkdf;
use function hash_hmac;
use function is_array;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rtrim;
use function sodium_crypto_aead_xchacha20poly1305_ietf_decrypt;
use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function strlen;
use function strtr;
use function substr;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

/**
 * Vault cryptography (1.34): opaque 256-bit tokens (base64url), their HMAC for lookups, and a blob sealed with
 * XChaCha20-Poly1305 under a key derived (HKDF-SHA256) from the token itself, bound to the entry (associated data).
 * The database alone opens nothing; the cookie alone holds no identity. Stateless.
 */
final readonly class VaultCrypto
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}\z/';

    public function __construct(
        private string $secret,
    ) {
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash_hmac('sha256', 'nowo-auth-kit-vault|' . $token, $this->secret);
    }

    /**
     * @param array<string, string|null> $payload
     */
    public function seal(string $token, string $associatedData, array $payload): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), $associatedData, $nonce, $this->key($token)));
    }

    /**
     * @return array<string, mixed>|null null when the blob does not open with this token and associated data
     */
    public function unseal(string $token, string $associatedData, string $sealed): ?array
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }
        // A wrong token, entry or tampered blob fails authentication (false); the key and nonce sizes are fixed.
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), $associatedData, substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), $this->key($token));
        $data  = $plain === false ? null : json_decode($plain, true, 4);

        return is_array($data) ? $data : null;
    }

    private function key(string $token): string
    {
        return hash_hkdf('sha256', $token, 32, 'nowo-auth-kit-vault-seal');
    }
}
