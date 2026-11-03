<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Totp;

use InvalidArgumentException;

use function chr;
use function implode;
use function ord;
use function preg_replace;
use function str_split;
use function strlen;
use function strpos;
use function strtoupper;

/**
 * RFC 4648 Base32 (upper case, no padding): the secret format of authenticator apps.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        $out    = '';
        $buffer = 0;
        $bits   = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; ++$i) {
            $buffer = (($buffer << 8) | ord($bytes[$i])) & 0xFFFF;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    /**
     * Decodes a Base32 string; spaces, dashes and `=` padding are ignored, case-insensitive.
     *
     * @throws InvalidArgumentException on a character outside the alphabet
     */
    public static function decode(string $encoded): string
    {
        $encoded = strtoupper((string) preg_replace('/[\s\-=]/', '', $encoded));
        $out     = '';
        $buffer  = 0;
        $bits    = 0;
        for ($i = 0, $n = strlen($encoded); $i < $n; ++$i) {
            $value = strpos(self::ALPHABET, $encoded[$i]);
            if ($value === false) {
                throw new InvalidArgumentException('Invalid Base32 character.');
            }
            $buffer = (($buffer << 5) | $value) & 0xFFFF;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $out;
    }

    /**
     * The secret in groups of four characters (manual entry).
     */
    public static function group(string $encoded): string
    {
        return $encoded === '' ? '' : implode(' ', str_split($encoded, 4));
    }
}
