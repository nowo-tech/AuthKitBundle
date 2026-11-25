<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use Psr\Clock\ClockInterface;

use function is_array;
use function is_string;
use function json_encode;
use function openssl_error_string;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_sign;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;

/**
 * Sign in with Apple's client secret (2.3.0): a short-lived ES256 JWT signed with the team's private key (the `.p8`
 * file from the Apple developer account, stored PEM-encoded as the credential's client secret, encrypted at rest).
 *
 * Header `{alg: ES256, kid: <key_id>}`; claims `iss` = team id, `iat` = now, `exp` = now + 5 minutes (Apple allows up to
 * six months; a fresh one per token request keeps a leaked one short-lived), `aud` = https://appleid.apple.com,
 * `sub` = the Services ID (client id). Built per call; nothing is kept. The key never reaches a message or a log.
 */
final class AppleClientSecret
{
    public const LIFETIME = 300;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws OidcException when the stored key is not a P-256 private key
     */
    public function generate(string $teamId, string $keyId, string $clientId, string $privateKeyPem): string
    {
        $key     = @openssl_pkey_get_private($privateKeyPem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        // Drain OpenSSL's error queue so nothing about the key surfaces in a later, unrelated error.
        while (openssl_error_string() !== false) {
        }
        $curve = null;
        if (is_array($details) && $details['type'] === OPENSSL_KEYTYPE_EC) {
            /** @var array{curve_name?: string} $ec */
            $ec    = $details['ec'];
            $curve = $ec['curve_name'] ?? null;
        }
        if ($key === false || $curve !== 'prime256v1') {
            throw new OidcException(OidcException::REASON_CLIENT_SECRET, 'the Apple client secret must be the PEM of the P-256 private key (.p8) of the Sign in with Apple key.');
        }

        $now     = $this->clock->now()->getTimestamp();
        $header  = Base64Url::encode(json_encode(['alg' => 'ES256', 'kid' => $keyId], JSON_THROW_ON_ERROR));
        $payload = Base64Url::encode(json_encode([
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + self::LIFETIME,
            'aud' => OidcProviderCatalog::APPLE_ISSUER,
            'sub' => $clientId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $der = '';
        openssl_sign($header . '.' . $payload, $der, $key, OPENSSL_ALGO_SHA256);
        $signature = JwkConverter::derToJose(is_string($der) ? $der : '', 32);
        if ($signature === null) {
            throw new OidcException(OidcException::REASON_CLIENT_SECRET, 'the Apple client secret could not be signed.'); // @codeCoverageIgnore
        }

        return $header . '.' . $payload . '.' . Base64Url::encode($signature);
    }
}
