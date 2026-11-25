<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use function base64_decode;
use function base64_encode;
use function preg_match;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;

/**
 * Unpadded base64url (RFC 4648 §5), the encoding of every binary WebAuthn value AuthKit stores or sends (2.4.0).
 *
 * @internal
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * The bytes, or null when `$value` is not unpadded base64url.
     */
    public static function decode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $value) !== 1 || strlen($value) % 4 === 1) {
            return null;
        }

        // Only the alphabet and a valid length get here: the strict decode cannot fail.
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
