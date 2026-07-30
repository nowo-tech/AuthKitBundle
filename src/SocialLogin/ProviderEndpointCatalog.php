<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use RuntimeException;

use function is_string;
use function sprintf;

/**
 * Resolves OAuth2 endpoint URLs and default scopes for known providers.
 */
final class ProviderEndpointCatalog
{
    /**
     * @return array{authorize_url: string, token_url: string, userinfo_url: string, scopes: list<string>}
     */
    public function resolve(SocialLoginCredential $credential): array
    {
        $defaults = $this->defaultsFor($credential->getProvider());

        $authorize = $credential->getAuthorizeUrl() ?? $defaults['authorize_url'] ?? null;
        $token     = $credential->getTokenUrl() ?? $defaults['token_url'] ?? null;
        $userinfo  = $credential->getUserinfoUrl() ?? $defaults['userinfo_url'] ?? null;
        $scopes    = $credential->getScopes() !== []
            ? $credential->getScopes()
            : ($defaults['scopes'] ?? ['openid', 'email', 'profile']);

        if (!is_string($authorize) || $authorize === '' || !is_string($token) || $token === '' || !is_string($userinfo) || $userinfo === '') {
            throw new RuntimeException(sprintf('Social provider "%s" is missing authorize/token/userinfo URLs. Set them on the credential or use a built-in provider key.', $credential->getProvider()));
        }

        return [
            'authorize_url' => $authorize,
            'token_url'     => $token,
            'userinfo_url'  => $userinfo,
            'scopes'        => $scopes,
        ];
    }

    /**
     * @return array{authorize_url?: string, token_url?: string, userinfo_url?: string, scopes?: list<string>}|array{}
     */
    private function defaultsFor(string $provider): array
    {
        return match (strtolower($provider)) {
            'google' => [
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url'     => 'https://oauth2.googleapis.com/token',
                'userinfo_url'  => 'https://openidconnect.googleapis.com/v1/userinfo',
                'scopes'        => ['openid', 'email', 'profile'],
            ],
            'github' => [
                'authorize_url' => 'https://github.com/login/oauth/authorize',
                'token_url'     => 'https://github.com/login/oauth/access_token',
                'userinfo_url'  => 'https://api.github.com/user',
                'scopes'        => ['read:user', 'user:email'],
            ],
            'microsoft' => [
                'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'userinfo_url'  => 'https://graph.microsoft.com/oidc/userinfo',
                'scopes'        => ['openid', 'email', 'profile'],
            ],
            default => [],
        };
    }
}
