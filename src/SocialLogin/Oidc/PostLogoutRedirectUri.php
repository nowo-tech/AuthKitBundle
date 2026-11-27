<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use Symfony\Component\HttpFoundation\Request;

use function in_array;
use function is_string;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;

/**
 * The `post_logout_redirect_uri` of an RP-initiated logout (2.8.0), chosen from the host's allow-list
 * (`social_login.rp_logout.post_logout_redirect_uris`) only, never from the request:
 *
 * - the sign-out's own target (the redirect the firewall's logout already answers) when it is on the list;
 * - else the first entry of the list;
 * - else none (the provider then shows its own signed-out page).
 *
 * An entry is a same-origin path (`/`, `/bye`; resolved like the firewall resolves its logout target: current scheme,
 * host and base URL) or an absolute http(s)
 * URL without credentials or fragment. The provider still checks the value against the URIs registered for the client.
 */
final class PostLogoutRedirectUri
{
    public const MAX_LENGTH = 2048;

    /**
     * Why an allow-list entry is refused, or null when it is valid (configuration check).
     */
    public static function entryError(mixed $entry): ?string
    {
        if (!is_string($entry) || $entry === '' || strlen($entry) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $entry) === 1) {
            return 'a non-empty path or URL of at most 2048 characters, without white space, control characters or backslashes';
        }
        if (str_starts_with($entry, '/')) {
            return str_starts_with($entry, '//') || str_contains($entry, '#') ? 'a same-origin path ("/..."), not "//host" and without fragment' : null;
        }
        $parts = parse_url($entry);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            return 'an absolute http(s) URL or a same-origin path';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return 'an absolute URL without credentials or fragment';
        }

        return null;
    }

    /**
     * @param list<string> $allowed the profile's allow-list (validated at build time)
     * @param string|null $target the sign-out's own redirect target (absolute URL or path), if any
     */
    public static function choose(array $allowed, ?string $target, Request $request): ?string
    {
        $resolved = [];
        foreach ($allowed as $entry) {
            if (self::entryError($entry) === null) {
                $resolved[] = str_starts_with($entry, '/') ? $request->getUriForPath($entry) : $entry;
            }
        }
        if ($resolved === []) {
            return null;
        }
        if ($target !== null && $target !== '') {
            $absolute = str_starts_with($target, '/') && !str_starts_with($target, '//') ? $request->getSchemeAndHttpHost() . $target : $target;
            if (in_array($absolute, $resolved, true)) {
                return $absolute;
            }
        }

        return $resolved[0];
    }
}
