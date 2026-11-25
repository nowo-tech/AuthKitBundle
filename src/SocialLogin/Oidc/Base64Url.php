<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use function base64_decode;
use function base64_encode;
use function preg_match;
use function rtrim;
use function strlen;
use function strtr;

/**
 * Base64url without padding (RFC 7515 §2), strict on decode: any character outside the URL-safe alphabet, padding or
 * an impossible length is refused instead of being skipped the way PHP's lenient base64_decode() would.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return string|null null when the input is not strict base64url
     */
    public static function decode(string $encoded): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_-]*\z/', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
