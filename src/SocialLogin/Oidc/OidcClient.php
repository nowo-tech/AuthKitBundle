<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use JsonException;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Pkce;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;

use function array_merge;
use function base64_encode;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function mb_substr;
use function random_bytes;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;
use function urlencode;

use const JSON_THROW_ON_ERROR;

/**
 * OpenID Connect sign-in (2.3.0): the generic provider (issuer + discovery) and the GitLab, Apple, LinkedIn and Slack
 * presets. Authorization-code flow only, with state, nonce (when the provider echoes it) and PKCE S256 (when the
 * provider advertises it, or `social_login.pkce` says so).
 *
 * The profile comes from the verified ID token; the userinfo endpoint, when the provider has one, completes it and
 * must name the same subject. Every outbound call goes through {@see OidcHttpClient} (SSRF guard, deadlines, size cap).
 *
 * Nothing is kept between calls: settings, metadata and keys are resolved per call (the last two through the cache
 * pool), so the service is safe in FrankenPHP worker mode with `kernel.reset` off (REQ-RUNTIME-002).
 */
final class OidcClient
{
    /** Longest Apple `user` form field accepted (first sign-in only: name and e-mail as JSON). */
    public const APPLE_USER_MAX_LENGTH = 4096;

    public function __construct(
        private readonly OidcProviderCatalog $catalog,
        private readonly OidcDiscovery $discovery,
        private readonly IdTokenValidator $idTokens,
        private readonly OidcHttpClient $http,
        private readonly OAuth2Client $oauth2Client,
        private readonly AppleClientSecret $appleClientSecret,
    ) {
    }

    public function supports(SocialLoginCredential $credential): bool
    {
        return $this->catalog->supports($credential);
    }

    /**
     * Whether the provider answers the callback with a cross-site POST (`response_mode=form_post`: Apple).
     */
    public function usesFormPost(SocialLoginCredential $credential): bool
    {
        try {
            return $this->catalog->supports($credential) && $this->catalog->resolve($credential)->formPost;
        } catch (OidcException) {
            return false;
        }
    }

    /**
     * Issues the state (with the PKCE verifier and the nonce kept server-side) and builds the authorization URL.
     *
     * @param array<string, bool> $pkceOverrides `social_login.pkce`
     * @param array<string, mixed> $authorizationParams `social_login.authorization_params`
     *
     * @throws OidcException
     *
     * @return array{state: string, url: string}
     */
    public function begin(SocialLoginCredential $credential, string $provider, string $redirectUri, SocialLoginStateStore $states, array $pkceOverrides = [], array $authorizationParams = []): array
    {
        $settings = $this->catalog->resolve($credential);
        $metadata = $this->discovery->metadata($settings->issuer);

        $verifier = $this->usesPkce($settings, $metadata, $pkceOverrides) ? Pkce::generateVerifier() : null;
        $nonce    = $settings->useNonce ? Base64Url::encode(random_bytes(32)) : null;
        $state    = $states->issue($provider, $verifier, $nonce);

        // The bundle's own protocol parameters are written last: nothing a host configures replaces them.
        $params = array_merge($this->oauth2Client->authorizationParams($credential, $authorizationParams), [
            'client_id'     => $credential->getClientId(),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $settings->scopes),
            'state'         => $state,
        ]);
        if ($nonce !== null) {
            $params['nonce'] = $nonce;
        }
        if ($verifier !== null) {
            $params['code_challenge']        = Pkce::challenge($verifier);
            $params['code_challenge_method'] = Pkce::METHOD;
        }
        if ($settings->formPost) {
            $params['response_mode'] = 'form_post';
        }

        $endpoint = $metadata->authorizationEndpoint;

        return ['state' => $state, 'url' => $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params)];
    }

    /**
     * Exchanges the code, verifies the ID token and builds the profile.
     *
     * @param string|null $appleUser Apple's `user` form field (first sign-in only): only the name is read from it; the
     *                               e-mail always comes from the signed ID token
     *
     * @throws OidcException
     *
     * @return array{0: array{access_token: string, refresh_token: ?string, expires_in: ?int, raw: array<string, mixed>}, 1: SocialUserProfile}
     */
    public function authenticate(SocialLoginCredential $credential, string $code, string $redirectUri, ?string $codeVerifier, ?string $nonce, ?string $appleUser = null): array
    {
        $settings = $this->catalog->resolve($credential);
        if ($settings->useNonce && $nonce === null) {
            throw new OidcException(OidcException::REASON_NONCE, 'no nonce was stored with the authorization request.');
        }
        $metadata = $this->discovery->metadata($settings->issuer);
        $tokens   = $this->exchange($settings, $metadata, $code, $redirectUri, $codeVerifier);

        $idToken = $tokens['raw']['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new OidcException(OidcException::REASON_TOKEN_RESPONSE, 'the token endpoint returned no ID token.');
        }
        $claims = $this->idTokens->validate($idToken, $metadata, $credential->getClientId(), $settings->useNonce ? $nonce : null);

        $userinfo = [];
        if ($metadata->userinfoEndpoint !== null) {
            $userinfo = $this->userinfo($metadata->userinfoEndpoint, $tokens['access_token'], $this->subject($claims));
        }

        return [$tokens, $this->profile($claims, $userinfo, $settings->kind === OidcProviderSettings::KIND_APPLE ? $appleUser : null)];
    }

    /**
     * The verified `sub` (IdTokenValidator refuses a token without a non-empty string one).
     *
     * @param array<string, mixed> $claims
     */
    private function subject(array $claims): string
    {
        $sub = $claims['sub'] ?? null;

        return is_string($sub) ? $sub : '';
    }

    /**
     * @param array<string, bool> $overrides
     */
    private function usesPkce(OidcProviderSettings $settings, OidcProviderMetadata $metadata, array $overrides): bool
    {
        $provider = strtolower(trim($settings->provider()));
        foreach ($overrides as $key => $enabled) {
            if (strtolower(trim((string) $key)) === $provider) {
                return (bool) $enabled;
            }
        }

        return $settings->pkceByDefault ?? $metadata->supportsS256();
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, raw: array<string, mixed>}
     */
    private function exchange(OidcProviderSettings $settings, OidcProviderMetadata $metadata, string $code, string $redirectUri, ?string $codeVerifier): array
    {
        $credential = $settings->credential;
        $secret     = $settings->kind === OidcProviderSettings::KIND_APPLE
            ? $this->appleClientSecret->generate((string) $settings->appleTeamId, (string) $settings->appleKeyId, $credential->getClientId(), $credential->getClientSecret())
            : $credential->getClientSecret();

        $body = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
            'client_id'    => $credential->getClientId(),
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $body['code_verifier'] = $codeVerifier;
        }
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if (($settings->tokenAuthMethod ?? $metadata->tokenAuthMethod()) === OidcProviderSettings::AUTH_CLIENT_SECRET_POST) {
            $body['client_secret'] = $secret;
        } else {
            // RFC 6749 §2.3.1: both parts form-urlencoded before the Basic encoding.
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($credential->getClientId()) . ':' . urlencode($secret));
        }

        $response = $this->http->requestJson('POST', $metadata->tokenEndpoint, 'token', ['headers' => $headers, 'body' => $body]);
        $data     = $response['data'];
        if ($response['status'] !== 200 || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new OidcException(OidcException::REASON_TOKEN_RESPONSE, 'the token endpoint did not return an access token.');
        }

        $refresh = $data['refresh_token'] ?? null;
        $expires = $data['expires_in'] ?? null;

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'expires_in'    => is_numeric($expires) ? (int) $expires : null,
            'raw'           => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userinfo(string $endpoint, string $accessToken, string $subject): array
    {
        $response = $this->http->requestJson('GET', $endpoint, 'userinfo', ['headers' => ['Authorization' => 'Bearer ' . $accessToken]]);
        if ($response['status'] !== 200) {
            throw new OidcException(OidcException::REASON_USERINFO, 'the userinfo endpoint did not answer 200.');
        }
        // OpenID Connect Core §5.3.2: a userinfo answer about another subject must not be used.
        if (($response['data']['sub'] ?? null) !== $subject) {
            throw new OidcException(OidcException::REASON_USERINFO, 'the userinfo subject is not the ID token subject.');
        }

        return $response['data'];
    }

    /**
     * The ID token's claims win; the e-mail and its verification come together from one source (the ID token when it
     * carries an e-mail, else userinfo), so a verification flag never applies to another address.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $userinfo
     */
    private function profile(array $claims, array $userinfo, ?string $appleUser): SocialUserProfile
    {
        $emailSource = $this->text($claims, 'email') !== null ? $claims : $userinfo;
        $email       = $this->text($emailSource, 'email');
        $verified    = $email === null ? null : $this->verified($emailSource['email_verified'] ?? null);

        $merged = array_merge($userinfo, $claims);
        $name   = $this->text($merged, 'name');
        if ($name === null) {
            $full = trim(($this->text($merged, 'given_name') ?? '') . ' ' . ($this->text($merged, 'family_name') ?? ''));
            $name = $full !== '' ? $full : ($this->text($merged, 'preferred_username') ?? $this->text($merged, 'nickname'));
        }
        $name ??= $this->appleName($appleUser);

        unset($merged['nonce']);

        return new SocialUserProfile($this->subject($claims), $email, $name, $merged, $verified);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function verified(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, ['true', 1, '1'], true)) {
            return true;
        }
        if (in_array($value, ['false', 0, '0'], true)) {
            return false;
        }

        return null;
    }

    /**
     * The name in Apple's first-sign-in `user` field (`{"name": {"firstName", "lastName"}, "email"}`), unsigned and so
     * used for the display name only.
     */
    private function appleName(?string $appleUser): ?string
    {
        if ($appleUser === null || $appleUser === '' || strlen($appleUser) > self::APPLE_USER_MAX_LENGTH) {
            return null;
        }

        try {
            $user = json_decode($appleUser, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $name = is_array($user) && is_array($user['name'] ?? null) ? $user['name'] : [];
        $full = trim(($this->text($name, 'firstName') ?? '') . ' ' . ($this->text($name, 'lastName') ?? ''));

        return $full === '' ? null : mb_substr($full, 0, 255);
    }
}
