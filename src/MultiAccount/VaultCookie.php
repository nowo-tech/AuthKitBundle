<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_filter;
use function array_slice;
use function array_unique;
use function array_values;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function time;

/**
 * The vault cookie (1.34): one opaque token per account, joined by dots, no identity inside; httpOnly, SameSite
 * Lax (or Strict), Secure and `__Host-` on HTTPS, path `/`, browser-session by default (D3). Changes are queued on
 * the request and written on the response ({@see self::write()}).
 */
final class VaultCookie
{
    /** Request attribute: profile => list of tokens to write (empty list = clear the cookie). */
    public const QUEUE_ATTRIBUTE = '_nowo_auth_kit_accounts_cookie';

    public static function name(Request $request, ProfileSettings $profile): string
    {
        $name = (string) ($profile->multiAccount['cookie']['name'] ?? 'nowo_auth_kit_accounts');

        return ($request->isSecure() ? '__Host-' : '') . $name . ($profile->name !== 'default' ? '_' . $profile->name : '');
    }

    /**
     * Well-formed tokens of the cookie (queued ones first, when this request already changed them).
     *
     * @return list<string>
     */
    public static function tokens(Request $request, ProfileSettings $profile): array
    {
        $queued = $request->attributes->get(self::QUEUE_ATTRIBUTE);
        if (is_array($queued) && is_array($queued[$profile->name] ?? null)) {
            return array_values(array_filter($queued[$profile->name], is_string(...)));
        }
        $value = $request->cookies->get(self::name($request, $profile));
        $max   = 2 * (int) ($profile->multiAccount['max_accounts'] ?? 5);

        return array_slice(array_values(array_unique(array_filter(
            explode('.', is_string($value) ? $value : ''),
            static fn (string $token): bool => preg_match(VaultCrypto::TOKEN_PATTERN, $token) === 1,
        ))), 0, $max);
    }

    /**
     * @param list<string> $tokens
     */
    public static function queue(Request $request, ProfileSettings $profile, array $tokens): void
    {
        $queued                 = $request->attributes->get(self::QUEUE_ATTRIBUTE);
        $queued                 = is_array($queued) ? $queued : [];
        $queued[$profile->name] = array_values(array_unique($tokens));
        $request->attributes->set(self::QUEUE_ATTRIBUTE, $queued);
    }

    /**
     * Writes the queued cookies of the profiles.
     *
     * @param array<string, ProfileSettings> $profiles
     */
    public static function write(Request $request, Response $response, array $profiles): void
    {
        $queued = $request->attributes->get(self::QUEUE_ATTRIBUTE);
        if (!is_array($queued)) {
            return;
        }
        foreach ($queued as $name => $tokens) {
            $profile = $profiles[$name] ?? null;
            if (!$profile instanceof ProfileSettings || !is_array($tokens)) {
                continue;
            }
            $cookie   = $profile->multiAccount['cookie'] ?? [];
            $lifetime = $cookie['lifetime'] ?? null;
            $expires  = $tokens === [] ? 1 : (is_int($lifetime) ? time() + $lifetime : 0);
            $response->headers->setCookie(Cookie::create(
                self::name($request, $profile),
                $tokens === [] ? null : implode('.', $tokens),
                $expires,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                ($cookie['samesite'] ?? 'lax') === 'strict' ? Cookie::SAMESITE_STRICT : Cookie::SAMESITE_LAX,
            ));
        }
    }
}
