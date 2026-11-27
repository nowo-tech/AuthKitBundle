<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\DependencyInjection\MfaConfiguration;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Profile\ProfileSettings;

use function array_is_list;
use function in_array;
use function is_array;
use function is_string;

/**
 * The per-credential policies of 2.7.0 resolved against the profile's (static helpers, no state).
 *
 * Fail closed: an unknown value is read as the strictest one, and `exempt_when_amr` exempts only when the `amr` claim of
 * the VALIDATED ID TOKEN ({@see SocialUserProfile::$idTokenClaims}) names an accepted value. The userinfo answer and the
 * merged `raw` profile are never read for it: a userinfo `amr` is not signed by the issuer.
 */
final class SocialCredentialPolicy
{
    /**
     * The `link_existing_account` policy that applies to a sign-in with this credential: its own, else the profile's.
     */
    public static function linkExistingAccount(?SocialLoginCredential $credential, ProfileSettings $profile): string
    {
        $own = $credential?->getLinkExistingAccount();
        if ($own !== null) {
            return $own;
        }
        $policy = $profile->socialLogin['link_existing_account'] ?? 'reset_password';

        return is_string($policy) && in_array($policy, SocialLoginCredential::LINK_EXISTING_ACCOUNT_POLICIES, true) ? $policy : 'deny';
    }

    /**
     * The MFA first-factor rule of a sign-in with this credential: {@see MfaConfiguration::RULE_REQUIRED},
     * {@see MfaConfiguration::RULE_EXEMPT}, or null when the credential has no policy of its own (the profile's
     * `mfa.first_factors.social` applies).
     */
    public static function mfaRule(SocialLoginCredential $credential, SocialUserProfile $socialProfile): ?string
    {
        return match ($credential->getMfaFirstFactor()) {
            null                                       => null,
            SocialLoginCredential::MFA_EXEMPT          => MfaConfiguration::RULE_EXEMPT,
            SocialLoginCredential::MFA_EXEMPT_WHEN_AMR => self::amrAccepted($socialProfile->idTokenClaims, $credential->getMfaAcceptedAmr())
                ? MfaConfiguration::RULE_EXEMPT
                : MfaConfiguration::RULE_REQUIRED,
            default => MfaConfiguration::RULE_REQUIRED,
        };
    }

    /**
     * Whether the ID token's `amr` (a JSON array of strings, RFC 8176 / OIDC Core §2) holds one accepted value.
     * No ID token (OAuth2 provider), no `amr`, a non-list `amr` or an empty accepted list: false.
     *
     * @param array<string, mixed>|null $idTokenClaims
     * @param list<string> $accepted
     */
    public static function amrAccepted(?array $idTokenClaims, array $accepted): bool
    {
        $amr = $idTokenClaims['amr'] ?? null;
        if ($accepted === [] || !is_array($amr) || !array_is_list($amr)) {
            return false;
        }
        foreach ($amr as $value) {
            if (is_string($value) && $value !== '' && in_array($value, $accepted, true)) {
                return true;
            }
        }

        return false;
    }
}
