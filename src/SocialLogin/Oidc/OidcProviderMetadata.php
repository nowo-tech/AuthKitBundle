<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use function in_array;

/**
 * The checked discovery document of an issuer (2.3.0). Every endpoint is an HTTPS URL that passed the SSRF guard.
 */
final readonly class OidcProviderMetadata
{
    /**
     * @param list<string> $codeChallengeMethods
     * @param list<string> $tokenAuthMethods
     * @param string|null $endSessionEndpoint 2.8.0: OpenID Connect RP-Initiated Logout 1.0 `end_session_endpoint`
     *                                        (public HTTPS, or null when the provider publishes none or an unsafe one)
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $userinfoEndpoint,
        public array $codeChallengeMethods = [],
        public array $tokenAuthMethods = [],
        public ?string $endSessionEndpoint = null,
    ) {
    }

    public function supportsS256(): bool
    {
        return in_array('S256', $this->codeChallengeMethods, true);
    }

    /**
     * The token endpoint authentication to use when the preset does not force one: the request body when the
     * provider lists it (what the bundle's OAuth2 providers use), else HTTP Basic, which OpenID Connect Discovery
     * makes the default when the document lists nothing.
     */
    public function tokenAuthMethod(): string
    {
        if (in_array(OidcProviderSettings::AUTH_CLIENT_SECRET_POST, $this->tokenAuthMethods, true)) {
            return OidcProviderSettings::AUTH_CLIENT_SECRET_POST;
        }

        return OidcProviderSettings::AUTH_CLIENT_SECRET_BASIC;
    }

    /**
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string, userinfo_endpoint: ?string, code_challenge_methods_supported: list<string>, token_endpoint_auth_methods_supported: list<string>, end_session_endpoint: ?string}
     */
    public function toArray(): array
    {
        return [
            'issuer'                                => $this->issuer,
            'authorization_endpoint'                => $this->authorizationEndpoint,
            'token_endpoint'                        => $this->tokenEndpoint,
            'jwks_uri'                              => $this->jwksUri,
            'userinfo_endpoint'                     => $this->userinfoEndpoint,
            'code_challenge_methods_supported'      => $this->codeChallengeMethods,
            'token_endpoint_auth_methods_supported' => $this->tokenAuthMethods,
            'end_session_endpoint'                  => $this->endSessionEndpoint,
        ];
    }
}
