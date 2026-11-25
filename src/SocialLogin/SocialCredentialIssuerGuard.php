<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcProviderCatalog;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcProviderSettings;

use function rtrim;
use function sprintf;
use function strtolower;
use function trim;

/**
 * For administration screens (2.3.0, audit ZBP-L1): whether the issuer of a credential may change.
 *
 * A provider key is one subject namespace only together with its issuer: `sub` 1234 on gitlab.com and `sub` 1234 on a
 * self-hosted GitLab are two different people. The sign-in already refuses a link made under another issuer
 * ({@see SocialAccountLinker}); this guard lets an admin form say so up front instead of leaving stale links behind.
 * While the provider has linked accounts, its issuer is locked: unlink them first, or create a new provider key. 2.4.0:
 * the effective issuers are compared (a preset without an issuer uses the preset's), never the OAuth2 endpoints.
 *
 * Stateless (REQ-RUNTIME-002).
 */
final class SocialCredentialIssuerGuard
{
    /** Provider key => issuer of the OpenID Connect presets ({@see OidcProviderCatalog}). */
    private const PRESET_ISSUERS = [
        OidcProviderSettings::KIND_GITLAB   => OidcProviderCatalog::GITLAB_ISSUER,
        OidcProviderSettings::KIND_APPLE    => OidcProviderCatalog::APPLE_ISSUER,
        OidcProviderSettings::KIND_LINKEDIN => OidcProviderCatalog::LINKEDIN_ISSUER,
        OidcProviderSettings::KIND_SLACK    => OidcProviderCatalog::SLACK_ISSUER,
    ];

    public const ERROR = 'The issuer of the "%s" provider cannot change while accounts are linked to it: they were linked under the current issuer. Unlink them first, or add the new issuer under a new provider key.';

    public function __construct(
        private readonly SocialLoginAccountRepository $accounts,
    ) {
    }

    /**
     * @return string|null why the change is refused, or null when it is allowed (same issuer, or no linked account)
     */
    public function issuerChangeError(SocialLoginCredential $credential, ?string $newIssuer): ?string
    {
        if (self::effective($credential, $credential->getIssuer()) === self::effective($credential, $newIssuer)) {
            return null;
        }
        if ($credential->getProvider() === '' || $this->accounts->count(['provider' => $credential->getProvider()]) === 0) {
            return null;
        }

        return sprintf(self::ERROR, $credential->getProvider());
    }

    /**
     * The issuer the sign-in uses (2.4.0, audit ZBP-v-I2): the normalised issuer, or for a `gitlab` / `apple` /
     * `linkedin` / `slack` credential without an issuer and without custom endpoints, its preset issuer
     * ({@see OidcProviderCatalog}). An explicit preset issuer and no issuer are then the same identity provider.
     */
    private static function effective(SocialLoginCredential $credential, ?string $issuer): ?string
    {
        $issuer = $issuer === null ? '' : rtrim(trim($issuer), '/');
        if ($issuer !== '') {
            return $issuer;
        }
        $custom = ($credential->getAuthorizeUrl() ?? '') !== '' || ($credential->getTokenUrl() ?? '') !== '' || ($credential->getUserinfoUrl() ?? '') !== '';

        return $custom ? null : (self::PRESET_ISSUERS[strtolower(trim($credential->getProvider()))] ?? null);
    }
}
