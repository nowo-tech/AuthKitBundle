<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use OpenSSLAsymmetricKey;

use function base64_encode;
use function chr;
use function chunk_split;
use function hex2bin;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function ord;
use function str_pad;
use function strlen;
use function substr;

use const OPENSSL_KEYTYPE_EC;
use const OPENSSL_KEYTYPE_RSA;
use const STR_PAD_LEFT;

/**
 * JSON Web Key (RFC 7517/7518) to an OpenSSL public key, and the ECDSA signature encodings (2.3.0).
 *
 * Built by hand in DER (SubjectPublicKeyInfo) so it works on every supported PHP and OpenSSL; only public RSA keys of
 * at least 2048 bits and EC keys on P-256 / P-384 / P-521 are accepted. A JWK carrying private members is refused: a
 * JWKS endpoint that publishes a private key is broken and is not trusted.
 */
final class JwkConverter
{
    public const MIN_RSA_BITS = 2048;

    /** curve => [DER of the curve OID, coordinate length in bytes] */
    private const CURVES = [
        'P-256' => ['06082a8648ce3d030107', 32],
        'P-384' => ['06052b81040022', 48],
        'P-521' => ['06052b81040023', 66],
    ];

    private const OID_RSA = '06092a864886f70d010101';
    private const OID_EC  = '06072a8648ce3d0201';

    /**
     * Coordinate length of the curve an ES* algorithm signs with.
     */
    public static function curveLength(string $algorithm): int
    {
        return match ($algorithm) {
            'ES384' => 48,
            'ES512' => 66,
            default => 32,
        };
    }

    /**
     * @param array<string, mixed> $jwk
     */
    public function toPublicKey(array $jwk): ?OpenSSLAsymmetricKey
    {
        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'k'] as $private) {
            if (isset($jwk[$private])) {
                return null;
            }
        }

        $der = match ($jwk['kty'] ?? null) {
            'RSA'   => $this->rsaDer($jwk),
            'EC'    => $this->ecDer($jwk),
            default => null,
        };
        if ($der === null) {
            return null;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)) {
            return null; // @codeCoverageIgnore
        }
        if ($details['type'] === OPENSSL_KEYTYPE_RSA && (!is_int($details['bits'] ?? null) || $details['bits'] < self::MIN_RSA_BITS)) {
            return null;
        }
        if ($details['type'] !== OPENSSL_KEYTYPE_RSA && $details['type'] !== OPENSSL_KEYTYPE_EC) {
            return null; // @codeCoverageIgnore
        }

        return $key;
    }

    /**
     * JOSE ECDSA signature (R || S, fixed length) to the DER SEQUENCE OpenSSL verifies.
     */
    public static function joseToDer(string $signature, int $length): ?string
    {
        if (strlen($signature) !== 2 * $length) {
            return null;
        }

        return self::sequence(self::integer(substr($signature, 0, $length)) . self::integer(substr($signature, $length)));
    }

    /**
     * DER SEQUENCE { INTEGER r, INTEGER s } (what openssl_sign() returns) to the JOSE R || S form.
     */
    public static function derToJose(string $der, int $length): ?string
    {
        $offset = 0;
        if (!self::readTag($der, $offset, 0x30)) {
            return null;
        }
        $out = '';
        for ($i = 0; $i < 2; ++$i) {
            if (!self::readTag($der, $offset, 0x02, $value)) {
                return null;
            }
            $value = ltrim((string) $value, "\0");
            if (strlen($value) > $length) {
                return null;
            }
            $out .= str_pad($value, $length, "\0", STR_PAD_LEFT);
        }

        return $offset === strlen($der) ? $out : null;
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function rsaDer(array $jwk): ?string
    {
        $n = is_string($jwk['n'] ?? null) ? Base64Url::decode($jwk['n']) : null;
        $e = is_string($jwk['e'] ?? null) ? Base64Url::decode($jwk['e']) : null;
        if ($n === null || $e === null || ltrim($n, "\0") === '' || ltrim($e, "\0") === '') {
            return null;
        }
        $key = self::sequence(self::integer($n) . self::integer($e));

        return self::sequence(self::sequence(hex2bin(self::OID_RSA) . "\x05\x00") . $this->bitString($key));
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function ecDer(array $jwk): ?string
    {
        $curve = $jwk['crv'] ?? null;
        if (!is_string($curve) || !isset(self::CURVES[$curve])) {
            return null;
        }
        [$oid, $length] = self::CURVES[$curve];
        $x              = is_string($jwk['x'] ?? null) ? Base64Url::decode($jwk['x']) : null;
        $y              = is_string($jwk['y'] ?? null) ? Base64Url::decode($jwk['y']) : null;
        if ($x === null || $y === null || strlen($x) !== $length || strlen($y) !== $length) {
            return null;
        }

        return self::sequence(self::sequence(hex2bin(self::OID_EC) . hex2bin($oid)) . $this->bitString("\x04" . $x . $y));
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0" . $bytes;
        }

        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::length(strlen($content)) . $content;
    }

    private function bitString(string $content): string
    {
        return "\x03" . self::length(strlen($content) + 1) . "\0" . $content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Reads one TLV with the expected tag at $offset; for a SEQUENCE only the header is consumed (its content is read
     * next), for anything else the value is returned in $value and skipped.
     */
    private static function readTag(string $der, int &$offset, int $tag, ?string &$value = null): bool
    {
        if ($offset + 2 > strlen($der) || ord($der[$offset]) !== $tag) {
            return false;
        }
        ++$offset;
        $length = ord($der[$offset++]);
        if ($length & 0x80) {
            $count = $length & 0x7F;
            if ($count === 0 || $count > 2 || $offset + $count > strlen($der)) {
                return false;
            }
            $length = 0;
            for ($i = 0; $i < $count; ++$i) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
        }
        if ($offset + $length > strlen($der)) {
            return false;
        }
        if ($tag === 0x30) {
            return $offset + $length === strlen($der);
        }
        $value = substr($der, $offset, $length);
        $offset += $length;

        return true;
    }
}
