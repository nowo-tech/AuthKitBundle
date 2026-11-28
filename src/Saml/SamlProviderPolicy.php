<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\DependencyInjection\MfaConfiguration;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\SocialLogin\SocialCredentialPolicy;

use function array_intersect;

/**
 * The per-row policies of a SAML provider (2.10.0), resolved against the profile's like the OpenID Connect credential
 * policies ({@see SocialCredentialPolicy}). Fail closed: no row (or an unknown stored value) is the strictest value.
 * Static helpers, no state.
 */
final class SamlProviderPolicy
{
    /**
     * The `link_existing_account` policy of a sign-in with this provider: its own, else the profile's; no row: `deny`.
     */
    public static function linkExistingAccount(?SamlProvider $provider, ProfileSettings $profile): string
    {
        if (!$provider instanceof SamlProvider) {
            return 'deny';
        }

        return $provider->getLinkExistingAccount() ?? SocialCredentialPolicy::linkExistingAccount(null, $profile);
    }

    /**
     * The MFA first-factor rule: {@see MfaConfiguration::RULE_REQUIRED}, {@see MfaConfiguration::RULE_EXEMPT}, or null
     * (the profile's `mfa.first_factors.social`). `exempt_when_authn_context` exempts only when the checked assertion's
     * `AuthnContextClassRef` is an accepted value.
     *
     * @param list<string> $authnContextClassRefs
     */
    public static function mfaRule(SamlProvider $provider, array $authnContextClassRefs): ?string
    {
        return match ($provider->getMfaFirstFactor()) {
            null                                       => null,
            SamlProvider::MFA_EXEMPT                   => MfaConfiguration::RULE_EXEMPT,
            SamlProvider::MFA_EXEMPT_WHEN_AUTHN_CONTEXT => array_intersect($authnContextClassRefs, $provider->getMfaAcceptedAuthnContexts()) !== []
                ? MfaConfiguration::RULE_EXEMPT
                : MfaConfiguration::RULE_REQUIRED,
            default => MfaConfiguration::RULE_REQUIRED,
        };
    }
}
