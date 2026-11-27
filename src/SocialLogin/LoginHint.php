<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use function filter_var;
use function is_string;
use function preg_match;
use function strlen;

use const FILTER_VALIDATE_EMAIL;

/**
 * The `login_hint` of a sign-in start (2.7.0, OpenID Connect Core §3.1.2.1): a dot-atom e-mail address the host already knows
 * (home-realm discovery), forwarded to the provider's authorization request so the user does not type it again.
 *
 * Only a plain dot-atom e-mail address (no quoted local part, no IP literal) of at most {@see self::MAX_LENGTH} bytes that
 * `FILTER_VALIDATE_EMAIL` also accepts is forwarded; anything else (another type, control characters, white space,
 * markup, an over-long value) is dropped, never echoed. The hint is advisory:
 * the provider's answer is checked as for any sign-in and the e-mail it asserts is not compared with the hint.
 */
final class LoginHint
{
    /** RFC 5321 path limit (254 octets for an address). */
    public const MAX_LENGTH = 254;

    public const PARAMETER = 'login_hint';

    /** A dot-atom local part and a host name of dot-separated labels (RFC 5322 dot-atom, RFC 1035 labels). */
    private const DOT_ATOM = '/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/D';

    /**
     * The hint when it is a usable e-mail address, else null.
     */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        // Audit ZCX-L1: plain dot-atom addresses only (no quoted local part, no IP literal: no `"`, `\`, `<`, `>`, `[`,
        // `]`, `(`, `)`), so AuthKit never relays markup to the provider's sign-in page.
        if (preg_match(self::DOT_ATOM, $value) !== 1) {
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
