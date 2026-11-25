<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\OAuthEndpointUrlValidator;

use function implode;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * Validation of the per-provider OIDC settings a credential carries (2.3.0): the issuer and the provider settings map
 * (Apple's team and key identifiers). Checked when the credential is written and again when a sign-in reads it (a row
 * written by raw SQL never skips them).
 */
final class OidcCredentialRules
{
    public const ISSUER_MAX_LENGTH = 512;

    /** Keys a credential's provider settings may hold. */
    public const SETTING_KEYS = ['team_id', 'key_id'];

    /**
     * @return string|null why the issuer is refused, or null when it is valid
     */
    public static function issuerError(string $issuer): ?string
    {
        if ($issuer === '' || strlen($issuer) > self::ISSUER_MAX_LENGTH) {
            return sprintf('The OIDC issuer must be a non-empty URL of at most %d characters.', self::ISSUER_MAX_LENGTH);
        }

        try {
            (new OAuthEndpointUrlValidator())->assertSafeHttpsUrl($issuer, 'issuer');
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $parts = parse_url($issuer);
        if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return 'The OIDC issuer must not carry a query, a fragment or credentials.';
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $settings
     *
     * @return string|null why the settings are refused, or null when they are valid
     */
    public static function settingsError(array $settings): ?string
    {
        foreach ($settings as $key => $value) {
            if (!in_array($key, self::SETTING_KEYS, true)) {
                return sprintf('Unknown social provider setting "%s" (known: %s).', (string) $key, implode(', ', self::SETTING_KEYS));
            }
            if ($value !== null && (!is_string($value) || preg_match('/\A[A-Z0-9]{10}\z/', $value) !== 1)) {
                return sprintf('The social provider setting "%s" must be the 10-character identifier from the Apple developer account.', $key);
            }
        }

        return null;
    }

    /**
     * @param array<string, string|null> $settings
     */
    public static function setting(array $settings, string $key): ?string
    {
        return $settings[$key] ?? null;
    }
}
