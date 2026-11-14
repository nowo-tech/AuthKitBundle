<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_key_exists;
use function array_merge;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function strpos;
use function strstr;
use function strtolower;
use function substr;
use function trim;
use function urldecode;

/**
 * Minimal OAuth2 authorization-code client (authorize URL + token + userinfo), with optional PKCE (S256).
 */
final class OAuth2Client
{
    public const DEFAULT_TIMEOUT = 10.0;

    public const DEFAULT_MAX_DURATION = 20.0;

    /**
     * Protocol parameters of the authorization request the bundle owns. A host cannot set or replace them through
     * `authorization_params`: moving `state` or `redirect_uri` would break the CSRF / PKCE binding of the callback
     * (an attacker-chosen `redirect_uri` is an open redirect and a code-leak), and `scope`, `client_id` and
     * `response_type` come from the credential and the provider catalogue. Setting one on a credential or in the
     * configuration is refused there; a value that still reaches the client (a row written by raw SQL) is dropped.
     *
     * @var list<string>
     */
    public const RESERVED_AUTHORIZATION_PARAMS = [
        'client_id',
        'client_secret',
        'redirect_uri',
        'response_type',
        'scope',
        'state',
        'code_challenge',
        'code_challenge_method',
        // 1.48 (audit L-1): at a JAR-capable authorization server (RFC 9101) the parameters inside a request object
        // REPLACE the query parameters, `redirect_uri` and `state` included, so `request` / `request_uri` would be a
        // way round every guard above. `response_mode` moves the response (and the code) to another channel.
        'request',
        'request_uri',
        'response_mode',
        // Belong to the protocol exchanges the bundle drives itself, not to a host's extra parameters.
        'nonce',
        'claims',
        'client_assertion',
        'client_assertion_type',
        'code',
        'grant_type',
    ];

    /**
     * @param float $timeout idle timeout in seconds for token/userinfo calls
     * @param float $maxDuration total time limit in seconds for token/userinfo calls
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ProviderEndpointCatalog $endpoints,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly float $maxDuration = self::DEFAULT_MAX_DURATION,
    ) {
    }

    /**
     * Resolves whether PKCE applies to this credential: an explicit `social_login.pkce` entry (provider key,
     * case-insensitive) wins; otherwise built-in providers known to support PKCE default to enabled.
     *
     * @param array<string, bool> $overrides
     */
    public function usesPkce(SocialLoginCredential $credential, array $overrides = []): bool
    {
        $provider = strtolower(trim($credential->getProvider()));
        foreach ($overrides as $key => $enabled) {
            if (strtolower(trim((string) $key)) === $provider) {
                return (bool) $enabled;
            }
        }

        return $this->endpoints->supportsPkceByDefault($provider);
    }

    /**
     * Extra authorization-request parameters of a credential (1.48), lowest precedence first: the defaults of a
     * built-in provider, the `social_login.authorization_params` entry of the profile, then the credential's own
     * map. A null value at any layer removes the parameter (so a host opts out of a default), and a later layer can
     * put it back. Reserved protocol parameters are dropped.
     *
     * Resolved from the arguments on every call, nothing memoised: worker safe with `kernel.reset` off
     * (REQ-RUNTIME-002).
     *
     * @param array<string, mixed> $overrides `social_login.authorization_params` (provider => map), keyed
     *                                        case-insensitively
     *
     * @return array<string, string>
     */
    public function authorizationParams(SocialLoginCredential $credential, array $overrides = []): array
    {
        $provider = strtolower(trim($credential->getProvider()));
        // Names are normalised before each merge, so a layer removes or replaces a parameter of a lower one whatever
        // the case and spacing it spells the name with.
        $merged = $this->normalizeParamNames($this->endpoints->defaultAuthorizationParams($provider));
        foreach ($overrides as $key => $params) {
            if (strtolower(trim((string) $key)) === $provider && is_array($params)) {
                $merged = array_merge($merged, $this->normalizeParamNames($params));
            }
        }
        $merged = array_merge($merged, $this->normalizeParamNames($credential->getAuthorizationParams()));

        $params = [];
        foreach ($merged as $name => $value) {
            if ($value === null || !is_scalar($value) || in_array($name, self::RESERVED_AUTHORIZATION_PARAMS, true)) {
                continue;
            }
            $params[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $params;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function normalizeParamNames(array $params): array
    {
        $normalized = [];
        foreach ($params as $name => $value) {
            $key = strtolower(trim((string) $name));
            if ($key !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param string|null $codeVerifier when set, adds `code_challenge` (S256) and `code_challenge_method`
     * @param array<string, mixed> $authorizationParams `social_login.authorization_params` of the profile; the
     *                                                  credential's own map always wins over it
     */
    public function buildAuthorizeUrl(SocialLoginCredential $credential, string $redirectUri, string $state, ?string $codeVerifier = null, array $authorizationParams = []): string
    {
        $resolved = $this->endpoints->resolve($credential);
        // The bundle's own protocol parameters are written last, so nothing a host configures can replace them.
        $params = array_merge($this->authorizationParams($credential, $authorizationParams), [
            'client_id'     => $credential->getClientId(),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $resolved['scopes']),
            'state'         => $state,
        ]);
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $params['code_challenge']        = Pkce::challenge($codeVerifier);
            $params['code_challenge_method'] = Pkce::METHOD;
        }
        // http_build_query percent-encodes every name and value (REQ: the host never assembles the query itself).
        $query = http_build_query($params);

        return $this->withQuery($resolved['authorize_url'], $params) . $query;
    }

    /**
     * The authorize URL with every parameter we are about to send dropped from its own query string, and ready for
     * the new query to be appended (1.48, audit L-2).
     *
     * Before `authorization_params` existed, the only way to send an extra parameter was to embed it in
     * `authorize_url` (e.g. `…/auth?prompt=consent`), which is supported and documented for custom IdPs. Appending
     * our own `prompt` next to it would repeat the parameter, which RFC 6749 §3.1 forbids and which Google and
     * Entra answer with `invalid_request` — so a host that did that would lose sign-in on upgrade without touching
     * anything. The parameter we send wins instead, and the rest of the host's query string is kept untouched,
     * byte for byte (no re-encoding, no array-key mangling).
     *
     * @param array<string, string> $params
     */
    private function withQuery(string $authorizeUrl, array $params): string
    {
        $mark = strpos($authorizeUrl, '?');
        if ($mark === false) {
            return $authorizeUrl . '?';
        }

        $base = substr($authorizeUrl, 0, $mark);
        $kept = [];
        foreach (explode('&', substr($authorizeUrl, $mark + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strstr($pair, '=', true);
            $name = strtolower(trim(urldecode($name === false ? $pair : $name)));
            if (!array_key_exists($name, $params)) {
                $kept[] = $pair;
            }
        }

        return $base . '?' . ($kept === [] ? '' : implode('&', $kept) . '&');
    }

    /**
     * @param string|null $codeVerifier PKCE verifier issued with the authorization request (sent as `code_verifier`)
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, raw: array<string, mixed>}
     */
    public function exchangeCode(SocialLoginCredential $credential, string $code, string $redirectUri, ?string $codeVerifier = null): array
    {
        $resolved = $this->endpoints->resolve($credential);
        $body     = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $credential->getClientId(),
            'client_secret' => $credential->getClientSecret(),
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $body['code_verifier'] = $codeVerifier;
        }
        $response = $this->httpClient->request('POST', $resolved['token_url'], [
            'timeout'      => $this->timeout,
            'max_duration' => $this->maxDuration,
            'headers'      => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);
        if (!isset($data['access_token']) || !is_string($data['access_token']) || $data['access_token'] === '') {
            throw new RuntimeException('OAuth token endpoint did not return an access_token.');
        }

        $refresh = $data['refresh_token'] ?? null;
        $expires = $data['expires_in'] ?? null;

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => is_string($refresh) ? $refresh : null,
            'expires_in'    => is_numeric($expires) ? (int) $expires : null,
            'raw'           => $data,
        ];
    }

    public function fetchUserProfile(SocialLoginCredential $credential, string $accessToken): SocialUserProfile
    {
        $resolved = $this->endpoints->resolve($credential);
        $response = $this->httpClient->request('GET', $resolved['userinfo_url'], [
            'timeout'      => $this->timeout,
            'max_duration' => $this->maxDuration,
            'headers'      => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);
        $id   = $this->firstString($data, ['id', 'sub', 'oid']);
        if ($id === null || $id === '') {
            throw new RuntimeException('OAuth userinfo response is missing a subject id.');
        }

        $emailVerified = $this->resolveEmailVerified($data);
        $email         = $this->firstString($data, ['email', 'mail']);
        if ($email === null && isset($data['emails']) && is_array($data['emails'])) {
            [$email, $emailVerified] = $this->extractGithubPrimaryEmail(array_values($data['emails']));
        }

        $name = $this->firstString($data, ['name', 'displayName', 'login']);

        return new SocialUserProfile($id, $email, $name, $data, $emailVerified);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveEmailVerified(array $data): ?bool
    {
        if (!array_key_exists('email_verified', $data)) {
            return null;
        }

        $value = $data['email_verified'];
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($data[$key]) || !is_scalar($data[$key])) {
                continue;
            }

            $value = trim((string) $data[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $emails
     *
     * @return array{0: ?string, 1: ?bool}
     */
    private function extractGithubPrimaryEmail(array $emails): array
    {
        foreach ($emails as $row) {
            if (!is_array($row)) {
                continue;
            }
            $address = $row['email'] ?? null;
            if (!is_string($address) || $address === '') {
                continue;
            }
            if (($row['primary'] ?? false) === true) {
                $verified = array_key_exists('verified', $row) ? (bool) $row['verified'] : null;

                return [$address, $verified];
            }
        }

        foreach ($emails as $row) {
            if (is_array($row) && isset($row['email']) && is_string($row['email']) && $row['email'] !== '') {
                $verified = array_key_exists('verified', $row) ? (bool) $row['verified'] : null;

                return [$row['email'], $verified];
            }
        }

        return [null, null];
    }
}
