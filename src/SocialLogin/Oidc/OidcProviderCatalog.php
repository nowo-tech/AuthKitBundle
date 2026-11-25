<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;

use function in_array;
use function rtrim;
use function strtolower;
use function trim;

/**
 * Which credentials sign in through OpenID Connect, and with which protocol choices (2.3.0).
 *
 * - `gitlab`, `apple`, `linkedin`, `slack`: presets with the provider's own issuer (GitLab's can be replaced by a
 *   self-hosted base URL), scopes and quirks;
 * - any other provider key whose credential sets an issuer: the generic provider (discovery from the issuer);
 * - everything else, including a preset key whose credential sets its own authorize / token / userinfo URLs (a custom
 *   OAuth2 setup made before 2.3.0), stays on the OAuth2 path of {@see OAuth2Client}.
 *
 * Pure resolution from the credential: nothing kept between calls (REQ-RUNTIME-002).
 */
final class OidcProviderCatalog
{
    public const APPLE_ISSUER    = 'https://appleid.apple.com';
    public const GITLAB_ISSUER   = 'https://gitlab.com';
    public const LINKEDIN_ISSUER = 'https://www.linkedin.com/oauth';
    public const SLACK_ISSUER    = 'https://slack.com';

    /** Provider keys with a preset. */
    public const PRESETS = [OidcProviderSettings::KIND_GITLAB, OidcProviderSettings::KIND_APPLE, OidcProviderSettings::KIND_LINKEDIN, OidcProviderSettings::KIND_SLACK];

    public function supports(SocialLoginCredential $credential): bool
    {
        $key = strtolower(trim($credential->getProvider()));
        if (!in_array($key, self::PRESETS, true)) {
            return $this->issuerOf($credential) !== null;
        }

        return $this->issuerOf($credential) !== null || !$this->hasCustomEndpoints($credential);
    }

    /**
     * @throws OidcException when the credential is an OIDC one but its settings are invalid
     */
    public function resolve(SocialLoginCredential $credential): OidcProviderSettings
    {
        if (!$this->supports($credential)) {
            throw new OidcException(OidcException::REASON_CONFIGURATION, 'the credential is not an OpenID Connect provider.');
        }

        $key    = strtolower(trim($credential->getProvider()));
        $issuer = $this->issuerOf($credential);

        $settings = match ($key) {
            OidcProviderSettings::KIND_GITLAB => new OidcProviderSettings(
                $credential,
                OidcProviderSettings::KIND_GITLAB,
                $issuer ?? self::GITLAB_ISSUER,
                $this->scopes($credential, ['openid', 'profile', 'email']),
                true,
                null,
                false,
                null,
            ),
            OidcProviderSettings::KIND_APPLE => new OidcProviderSettings(
                $credential,
                OidcProviderSettings::KIND_APPLE,
                $this->fixedIssuer($issuer, self::APPLE_ISSUER, 'apple'),
                // Apple's scopes are "name" and "email"; the ID token comes back without "openid".
                $credential->getScopes() !== [] ? $credential->getScopes() : ['name', 'email'],
                true,
                OidcProviderSettings::AUTH_CLIENT_SECRET_POST,
                // Apple requires response_mode=form_post when name or email is requested.
                true,
                false,
                OidcCredentialRules::setting($credential->getProviderSettings(), 'team_id'),
                OidcCredentialRules::setting($credential->getProviderSettings(), 'key_id'),
            ),
            OidcProviderSettings::KIND_LINKEDIN => new OidcProviderSettings(
                $credential,
                OidcProviderSettings::KIND_LINKEDIN,
                $this->fixedIssuer($issuer, self::LINKEDIN_ISSUER, 'linkedin'),
                $this->scopes($credential, ['openid', 'profile', 'email']),
                // LinkedIn does not echo the nonce in its ID token: none is sent (state, and the code bound to the
                // client secret, still protect the exchange).
                false,
                // LinkedIn's discovery document lists no token endpoint authentication method; it takes the body.
                OidcProviderSettings::AUTH_CLIENT_SECRET_POST,
                false,
                false,
            ),
            OidcProviderSettings::KIND_SLACK => new OidcProviderSettings(
                $credential,
                OidcProviderSettings::KIND_SLACK,
                $this->fixedIssuer($issuer, self::SLACK_ISSUER, 'slack'),
                $this->scopes($credential, ['openid', 'profile', 'email']),
                true,
                OidcProviderSettings::AUTH_CLIENT_SECRET_POST,
                false,
                null,
            ),
            default => new OidcProviderSettings(
                $credential,
                OidcProviderSettings::KIND_GENERIC,
                (string) $issuer,
                $this->scopes($credential, ['openid', 'email', 'profile']),
                true,
                null,
                false,
                null,
            ),
        };

        $this->assertValid($settings);

        return $settings;
    }

    private function assertValid(OidcProviderSettings $settings): void
    {
        $error = OidcCredentialRules::issuerError($settings->issuer)
            ?? OidcCredentialRules::settingsError($settings->credential->getProviderSettings());
        if ($error !== null) {
            throw new OidcException(OidcException::REASON_CONFIGURATION, $error);
        }
        if ($settings->credential->getClientId() === '') {
            throw new OidcException(OidcException::REASON_CONFIGURATION, 'the credential has no client id.');
        }
        if ($settings->kind === OidcProviderSettings::KIND_APPLE) {
            if ($settings->appleTeamId === null || $settings->appleKeyId === null) {
                throw new OidcException(OidcException::REASON_CONFIGURATION, 'Sign in with Apple needs the team_id and key_id provider settings.');
            }
        }
        if ($settings->credential->getClientSecret() === '') {
            throw new OidcException(OidcException::REASON_CONFIGURATION, 'the credential has no client secret.');
        }
    }

    /**
     * The preset's issuer; a credential may repeat it (with or without a trailing slash) but never point elsewhere.
     */
    private function fixedIssuer(?string $issuer, string $preset, string $provider): string
    {
        if ($issuer !== null && rtrim($issuer, '/') !== $preset) {
            throw new OidcException(OidcException::REASON_CONFIGURATION, 'the issuer of the ' . $provider . ' provider is fixed and cannot be changed.');
        }

        return $preset;
    }

    /**
     * @param list<string> $defaults
     *
     * @return list<string>
     */
    private function scopes(SocialLoginCredential $credential, array $defaults): array
    {
        $scopes = $credential->getScopes() !== [] ? $credential->getScopes() : $defaults;
        if (!in_array('openid', $scopes, true)) {
            $scopes[] = 'openid';
        }

        return $scopes;
    }

    private function issuerOf(SocialLoginCredential $credential): ?string
    {
        $issuer = $credential->getIssuer();

        return $issuer === null || trim($issuer) === '' ? null : trim($issuer);
    }

    private function hasCustomEndpoints(SocialLoginCredential $credential): bool
    {
        return ($credential->getAuthorizeUrl() ?? '') !== ''
            || ($credential->getTokenUrl() ?? '') !== ''
            || ($credential->getUserinfoUrl() ?? '') !== '';
    }
}
