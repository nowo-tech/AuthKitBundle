<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\OAuthEndpointUrlValidator;

use function array_key_last;
use function explode;
use function filter_var;
use function is_string;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * 2.8.0 (audit ZDC-L1): the browser-side rule of an RP-initiated logout endpoint, applied when discovery reads
 * `end_session_endpoint` and again at sign-out. The endpoint is never fetched by the server (no DNS check is possible
 * here), so the host itself must be unambiguous and public:
 *
 * - HTTPS, at most {@see OidcDiscovery::MAX_OPTIONAL_ENDPOINT_LENGTH} characters, no credentials, no fragment;
 * - no control characters, white space or `"<>\^`{|}`;
 * - an IP literal (IPv6 in brackets, or a host whose last label is numeric or hex, which browsers read as IPv4 in any
 *   of its short / hex / octal spellings) only as a canonical address that {@see PublicIpChecker} accepts;
 * - a host name with at least two labels (no `localhost`, `intranet` or other single-label names);
 * - and {@see OAuthEndpointUrlValidator}'s checks.
 *
 * Pure function of its argument (worker-safe).
 */
final class EndSessionEndpointRule
{
    public static function isSafe(mixed $url): bool
    {
        if (!is_string($url) || $url === '' || strlen($url) > OidcDiscovery::MAX_OPTIONAL_ENDPOINT_LENGTH
            || preg_match('/[\x00-\x20\x7F-\xFF"<>\\\\^`{|}]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        try {
            (new OAuthEndpointUrlValidator())->assertSafeHttpsUrl($url, 'end_session_endpoint');
        } catch (InvalidArgumentException) {
            return false;
        }

        return self::isPublicHost(strtolower($parts['host']));
    }

    private static function isPublicHost(string $host): bool
    {
        if (str_starts_with($host, '[') || str_ends_with($host, ']')) {
            $ip = substr($host, 1, -1);

            return str_starts_with($host, '[') && str_ends_with($host, ']')
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && (new PublicIpChecker())->isPublic($ip);
        }
        $name   = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        $labels = explode('.', $name);
        $last   = $labels[array_key_last($labels)];
        // WHATWG URL: a host whose last label is a number is an IPv4 address (127.1, 0x7f.0.0.1, 2130706433, 0177.0.0.1).
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', $last) === 1) {
            return filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (new PublicIpChecker())->isPublic($name);
        }

        return str_contains($name, '.') && preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $name) === 1;
    }
}
