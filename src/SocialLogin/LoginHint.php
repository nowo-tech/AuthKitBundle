<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use function filter_var;
use function is_string;
use function preg_match;
use function strlen;

use const FILTER_VALIDATE_EMAIL;

/**
 * The `login_hint` of a sign-in start (2.7.0, OpenID Connect Core §3.1.2.1): an e-mail address the host already knows
 * (home-realm discovery), forwarded to the provider's authorization request so the user does not type it again.
 *
 * Only a syntactically valid e-mail address of at most {@see self::MAX_LENGTH} bytes is forwarded; anything else
 * (another type, control characters, white space, an over-long value) is dropped, never echoed. The hint is advisory:
 * the provider's answer is checked as for any sign-in and the e-mail it asserts is not compared with the hint.
 */
final class LoginHint
{
    /** RFC 5321 path limit (254 octets for an address). */
    public const MAX_LENGTH = 254;

    public const PARAMETER = 'login_hint';

    /**
     * The hint when it is a usable e-mail address, else null.
     */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /**
     * The authorization parameters for a hint (none for null or an invalid one).
     *
     * @return array<string, string>
     */
    public static function params(?string $hint): array
    {
        $hint = self::normalize($hint);

        return $hint === null ? [] : [self::PARAMETER => $hint];
    }
}
