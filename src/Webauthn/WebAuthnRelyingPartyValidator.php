<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use function count;
use function ctype_digit;
use function filter_var;
use function json_encode;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function strrchr;
use function substr;

use const FILTER_VALIDATE_IP;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The rules every WebAuthn relying party must meet before AuthKit issues or verifies a ceremony (2.6.0), whatever
 * {@see WebAuthnRelyingPartyProviderInterface} answered. Use it in your settings form to refuse a value before it is
 * saved; AuthKit checks it again on every ceremony and fails closed.
 *
 * RP id: a host name of lower-case LDH labels (letters, digits, hyphens; at most 63 characters a label, 253 in all), no
 * scheme, port, path or trailing dot; not an IP literal (browsers refuse one); at least two labels, except `localhost`;
 * the last label is not all digits. A public suffix (`co.uk`, `github.io`) is not detected: that needs the Public Suffix
 * List, a dependency AuthKit does not take; browsers refuse such an RP id themselves (the ceremony fails in the browser,
 * never on the server).
 *
 * Origins: at least one and at most {@see self::MAX_ORIGINS}; each exactly `https://host[:port]` (lower case, no path,
 * query, fragment or user info, no explicit default port), or `http://localhost[:port]` when `$allowHttpLocalhost`
 * (the profile's `webauthn.allow_http_localhost`, else `kernel.debug`); each host is the RP id or a sub-domain of it.
 *
 * Stateless.
 */
final class WebAuthnRelyingPartyValidator
{
    public const MAX_ORIGINS = 50;

    private const HOST = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/D';

    private const ORIGIN = '#^(https|http)://([^/?\#@:\s]+)(?::([1-9]\d{0,4}))?$#D';

    /**
     * Every rule the relying party breaks (empty: valid). The messages name the values JSON-encoded (an RP id and
     * origins are not secret; encoding keeps a control character from forging a log line) and are meant for logs and
     * settings forms. Strict: a settings form refuses any unusable origin (at runtime AuthKit only drops it, see
     * {@see self::originViolation()}).
     *
     * @param list<string> $origins
     *
     * @return list<string>
     */
    public static function violations(string $rpId, array $origins, bool $allowHttpLocalhost = false): array
    {
        $violations = [];
        $rpIdValid  = self::isValidRpId($rpId);
        if (!$rpIdValid) {
            $violations[] = self::rpIdViolation($rpId);
        }
        if ($origins === []) {
            $violations[] = 'No allowed origin.';
        }
        if (count($origins) > self::MAX_ORIGINS) {
            $violations[] = sprintf('More than %d allowed origins.', self::MAX_ORIGINS);
        }
        foreach ($origins as $origin) {
            $violation = self::originViolation($origin, $rpIdValid ? $rpId : null, $allowHttpLocalhost);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Why one origin cannot be used with the RP id, or null when it can (2.6.0, audit ZCQ-M2: the runtime drops such an
     * origin and keeps the others). `$rpId` null: only the form of the origin is checked.
     */
    public static function originViolation(string $origin, ?string $rpId, bool $allowHttpLocalhost = false): ?string
    {
        if (preg_match(self::ORIGIN, $origin, $parts) !== 1 || preg_match(self::HOST, $parts[2]) !== 1 || (int) ($parts[3] ?? 0) > 65535
            || ($parts[1] === 'http' && (!$allowHttpLocalhost || $parts[2] !== 'localhost'))) {
            return sprintf('The origin %s is not https://host[:port]%s.', self::quote($origin), $allowHttpLocalhost ? ' or http://localhost[:port]' : '');
        }
        // Audit ZCQ-I4: a browser origin never names its default port, so such an entry would never match.
        if (($parts[3] ?? '') === ($parts[1] === 'https' ? '443' : '80')) {
            return sprintf('The origin %s names the default port: leave it out.', self::quote($origin));
        }
        if ($rpId !== null && !self::isHostOfRpId($parts[2], $rpId)) {
            return sprintf('The origin %s is not on the RP id %s or a sub-domain of it.', self::quote($origin), self::quote($rpId));
        }

        return null;
    }

    /**
     * The message of an RP id that {@see self::isValidRpId()} refuses.
     */
    public static function rpIdViolation(string $rpId): string
    {
        return sprintf('The RP id %s is not a registrable host name (lower case, no scheme, port or IP address).', self::quote($rpId));
    }

    /**
     * Whether a value can be an RP id (see the class rules).
     */
    public static function isValidRpId(string $rpId): bool
    {
        if (preg_match(self::HOST, $rpId) !== 1 || filter_var($rpId, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $last = strrchr($rpId, '.');
        if ($last === false) {
            return $rpId === 'localhost';
        }

        return !ctype_digit(substr($last, 1));
    }

    /**
     * Whether `$host` is the RP id or a sub-domain of it (the WebAuthn "registrable domain suffix" relation, without the
     * Public Suffix List).
     */
    public static function isHostOfRpId(string $host, string $rpId): bool
    {
        return $host === $rpId || str_ends_with($host, '.' . $rpId);
    }

    /**
     * A value as it appears in a message: JSON (quotes, control characters escaped).
     */
    private static function quote(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
