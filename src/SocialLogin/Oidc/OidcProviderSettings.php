<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;

/**
 * The resolved OpenID Connect settings of one credential (2.3.0): its preset (gitlab, apple, linkedin, slack or the
 * generic provider), the issuer, the scopes and the protocol choices the preset makes. Built per call by
 * {@see OidcProviderCatalog}; never cached in a service.
 */
final readonly class OidcProviderSettings
{
    public const KIND_GENERIC  = 'oidc';
    public const KIND_GITLAB   = 'gitlab';
    public const KIND_APPLE    = 'apple';
    public const KIND_LINKEDIN = 'linkedin';
    public const KIND_SLACK    = 'slack';

    public const AUTH_CLIENT_SECRET_POST  = 'client_secret_post';
    public const AUTH_CLIENT_SECRET_BASIC = 'client_secret_basic';

    /**
     * @param list<string> $scopes
     * @param string|null $tokenAuthMethod forced token endpoint authentication; null: from the discovery document
     * @param bool|null $pkceByDefault null: PKCE when the discovery document advertises S256
     */
    public function __construct(
        public SocialLoginCredential $credential,
        public string $kind,
        public string $issuer,
        public array $scopes,
        public bool $useNonce,
        public ?string $tokenAuthMethod,
        public bool $formPost,
        public ?bool $pkceByDefault,
        public ?string $appleTeamId = null,
        public ?string $appleKeyId = null,
    ) {
    }

    public function provider(): string
    {
        return $this->credential->getProvider();
    }
}
