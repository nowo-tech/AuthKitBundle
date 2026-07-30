<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;

/**
 * Minimal OAuth2 authorization-code client (authorize URL + token + userinfo).
 */
final class OAuth2Client
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ProviderEndpointCatalog $endpoints,
    ) {
    }

    public function buildAuthorizeUrl(SocialLoginCredential $credential, string $redirectUri, string $state): string
    {
        $resolved = $this->endpoints->resolve($credential);
        $query    = http_build_query([
            'client_id'     => $credential->getClientId(),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $resolved['scopes']),
            'state'         => $state,
        ]);

        return $resolved['authorize_url'] . (str_contains($resolved['authorize_url'], '?') ? '&' : '?') . $query;
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, raw: array<string, mixed>}
     */
    public function exchangeCode(SocialLoginCredential $credential, string $code, string $redirectUri): array
    {
        $resolved = $this->endpoints->resolve($credential);
        $response = $this->httpClient->request('POST', $resolved['token_url'], [
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type'    => 'authorization_code',
                'client_id'     => $credential->getClientId(),
                'client_secret' => $credential->getClientSecret(),
                'redirect_uri'  => $redirectUri,
                'code'          => $code,
            ],
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
            'headers' => [
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
