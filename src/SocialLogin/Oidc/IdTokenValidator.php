<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use JsonException;
use OpenSSLAsymmetricKey;
use Psr\Clock\ClockInterface;

use function array_is_list;
use function count;
use function explode;
use function hash_equals;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function openssl_verify;
use function sprintf;
use function strlen;
use function strtoupper;
use function substr;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_ALGO_SHA512;

/**
 * Verifies an ID token (OpenID Connect Core §3.1.3.7, 2.3.0) and returns its claims.
 *
 * - JWS compact form only; `alg` on the `oidc.allowed_algorithms` allow-list (RS*, ES*; never `none` nor HS*), no
 *   `crit` header, and the key always comes from the issuer's JWKS (a `jwk` / `jku` / `x5u` header is never used);
 * - the key must fit the algorithm (`kty`, curve, `use: sig` when set, `alg` when set, RSA >= 2048 bits);
 * - `iss` is the discovered issuer exactly; `aud` holds the client id; with several audiences `azp` is required, and
 *   whenever `azp` is present it is the client id;
 * - `exp` in the future, `iat` not in the future and not older than `oidc.max_id_token_age`, `nbf` reached, each with
 *   `oidc.clock_skew` seconds of leeway;
 * - `nonce` equal (constant time) to the one stored with the state, when one was sent;
 * - `sub` a non-empty string.
 *
 * Stateless (REQ-RUNTIME-002).
 */
final class IdTokenValidator
{
    public const MAX_LENGTH = 65536;

    public function __construct(
        private readonly JwksProvider $jwks,
        private readonly OidcOptions $options,
        private readonly ClockInterface $clock,
        private readonly JwkConverter $converter = new JwkConverter(),
    ) {
    }

    /**
     * @throws OidcException
     *
     * @return array<string, mixed> the verified claims
     */
    public function validate(string $idToken, OidcProviderMetadata $metadata, string $clientId, ?string $expectedNonce): array
    {
        if (strlen($idToken) > self::MAX_LENGTH) {
            throw new OidcException(OidcException::REASON_ID_TOKEN, 'the ID token is too large.');
        }
        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || $parts[2] === '') {
            throw new OidcException(OidcException::REASON_ID_TOKEN, 'the ID token is not a signed JWT.');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header    = $this->decodeJson($encodedHeader, 'header');
        $claims    = $this->decodeJson($encodedPayload, 'payload');
        $signature = Base64Url::decode($encodedSignature);
        if ($signature === null) {
            throw new OidcException(OidcException::REASON_ID_TOKEN, 'the ID token signature is not base64url.');
        }

        $algorithm = $header['alg'] ?? null;
        if (!is_string($algorithm) || !in_array($algorithm, $this->options->allowedAlgorithms, true)) {
            throw new OidcException(OidcException::REASON_SIGNATURE, 'the ID token algorithm is not on the allow-list.');
        }
        if (isset($header['crit'])) {
            throw new OidcException(OidcException::REASON_ID_TOKEN, 'the ID token has critical header parameters, which are not supported.');
        }
        $typ = $header['typ'] ?? null;
        if ($typ !== null && (!is_string($typ) || strtoupper($typ) !== 'JWT')) {
            throw new OidcException(OidcException::REASON_ID_TOKEN, 'the ID token type is not JWT.');
        }
        $kid = $header['kid'] ?? null;
        if ($kid !== null && !is_string($kid)) {
            throw new OidcException(OidcException::REASON_ID_TOKEN, 'the ID token key id is not a string.');
        }

        $this->verifySignature($encodedHeader . '.' . $encodedPayload, $signature, $algorithm, $metadata->jwksUri, $kid);
        $this->verifyClaims($claims, $metadata->issuer, $clientId, $expectedNonce);

        return $claims;
    }

    private function verifySignature(string $input, string $signature, string $algorithm, string $jwksUri, ?string $kid): void
    {
        $family = substr($algorithm, 0, 2);
        $digest = match (substr($algorithm, 2)) {
            '384'   => OPENSSL_ALGO_SHA384,
            '512'   => OPENSSL_ALGO_SHA512,
            default => OPENSSL_ALGO_SHA256,
        };
        if ($family === 'ES') {
            $der = JwkConverter::joseToDer($signature, JwkConverter::curveLength($algorithm));
            if ($der === null) {
                throw new OidcException(OidcException::REASON_SIGNATURE, 'the ID token ECDSA signature has the wrong length.');
            }
            $signature = $der;
        }

        foreach ($this->jwks->keys($jwksUri, $kid) as $jwk) {
            if (!$this->fits($jwk, $algorithm)) {
                continue;
            }
            $key = $this->converter->toPublicKey($jwk);
            if ($key instanceof OpenSSLAsymmetricKey && openssl_verify($input, $signature, $key, $digest) === 1) {
                return;
            }
        }

        throw new OidcException(OidcException::REASON_SIGNATURE, 'no key of the issuer verifies the ID token signature.');
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function fits(array $jwk, string $algorithm): bool
    {
        $use = $jwk['use'] ?? null;
        if ($use !== null && $use !== 'sig') {
            return false;
        }
        $keyAlgorithm = $jwk['alg'] ?? null;
        if ($keyAlgorithm !== null && $keyAlgorithm !== $algorithm) {
            return false;
        }
        $ops = $jwk['key_ops'] ?? null;
        if (is_array($ops) && !in_array('verify', $ops, true)) {
            return false;
        }

        return match (substr($algorithm, 0, 2)) {
            'RS'    => ($jwk['kty'] ?? null) === 'RSA',
            default => ($jwk['kty'] ?? null) === 'EC' && ($jwk['crv'] ?? null) === match ($algorithm) {
                'ES384' => 'P-384',
                'ES512' => 'P-521',
                default => 'P-256',
            },
        };
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function verifyClaims(array $claims, string $issuer, string $clientId, ?string $expectedNonce): void
    {
        if (($claims['iss'] ?? null) !== $issuer) {
            throw new OidcException(OidcException::REASON_CLAIMS, 'the ID token issuer is not the provider\'s issuer.');
        }

        $audience  = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) && array_is_list($audience) ? $audience : []);
        if (!in_array($clientId, $audiences, true)) {
            throw new OidcException(OidcException::REASON_CLAIMS, 'the ID token is not for this client (aud).');
        }
        $azp = $claims['azp'] ?? null;
        if (count($audiences) > 1 && $azp === null) {
            throw new OidcException(OidcException::REASON_CLAIMS, 'the ID token has several audiences and no authorized party (azp).');
        }
        if ($azp !== null && $azp !== $clientId) {
            throw new OidcException(OidcException::REASON_CLAIMS, 'the ID token authorized party (azp) is another client.');
        }

        $now  = $this->clock->now()->getTimestamp();
        $skew = $this->options->clockSkew;
        $exp  = $this->time($claims, 'exp', true);
        if ($exp === null || $now >= $exp + $skew) {
            throw new OidcException(OidcException::REASON_CLAIMS, 'the ID token has expired.');
        }
        $iat = $this->time($claims, 'iat', true);
        if ($iat === null || $iat > $now + $skew || $now - $iat > $this->options->maxIdTokenAge + $skew) {
            throw new OidcException(OidcException::REASON_CLAIMS, 'the ID token issue time (iat) is in the future or too old.');
        }
        $nbf = $this->time($claims, 'nbf', false);
        if ($nbf !== null && $nbf > $now + $skew) {
            throw new OidcException(OidcException::REASON_CLAIMS, 'the ID token is not valid yet (nbf).');
        }

        if ($expectedNonce !== null) {
            $nonce = $claims['nonce'] ?? null;
            if (!is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
                throw new OidcException(OidcException::REASON_NONCE, 'the ID token nonce does not match the authorization request.');
            }
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new OidcException(OidcException::REASON_CLAIMS, 'the ID token has no subject (sub).');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function time(array $claims, string $name, bool $required): ?int
    {
        $value = $claims[$name] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if ($value !== null || $required) {
            throw new OidcException(OidcException::REASON_CLAIMS, sprintf('the ID token %s claim is missing or not a number.', $name));
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $encoded, string $part): array
    {
        $json = Base64Url::decode($encoded);
        if ($json === null) {
            throw new OidcException(OidcException::REASON_ID_TOKEN, sprintf('the ID token %s is not base64url.', $part));
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OidcException(OidcException::REASON_ID_TOKEN, sprintf('the ID token %s is not JSON.', $part), $e);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || $data === []) {
            throw new OidcException(OidcException::REASON_ID_TOKEN, sprintf('the ID token %s is not a JSON object.', $part));
        }

        /** @var array<string, mixed> $typed */
        $typed = $data;

        return $typed;
    }
}
