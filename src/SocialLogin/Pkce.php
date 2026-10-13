<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use function rtrim;
use function strtr;

/**
 * Proof Key for Code Exchange (RFC 7636) helpers — S256 method only.
 */
final class Pkce
{
    public const METHOD = 'S256';

    /**
     * 43-character base64url verifier built from 32 random bytes (RFC 7636 §4.1, 256 bits of entropy).
     */
    public static function generateVerifier(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    /**
     * BASE64URL(SHA256(ASCII(code_verifier))) (RFC 7636 §4.2).
     */
    public static function challenge(string $codeVerifier): string
    {
        return self::base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
