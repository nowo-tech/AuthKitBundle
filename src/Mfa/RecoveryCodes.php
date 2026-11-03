<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use function array_values;
use function count;
use function hash_hmac;
use function preg_match;
use function preg_replace;
use function random_int;
use function strlen;
use function strtoupper;
use function strtr;
use function substr;

/**
 * Recovery codes (1.34): 10 Crockford Base32 characters (~50 bits) shown as `XXXXX-XXXXX`; only an HMAC is stored.
 * Input is normalised (case, dashes, spaces, the look-alikes O → 0 and I / L → 1).
 */
final readonly class RecoveryCodes
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LENGTH = 10;

    public function __construct(
        private string $secret,
    ) {
    }

    /**
     * @return list<string> display form
     */
    public function generate(int $count): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $code = '';
            for ($i = 0; $i < self::LENGTH; ++$i) {
                $code .= self::ALPHABET[random_int(0, 31)];
            }
            $codes[$code] = substr($code, 0, 5) . '-' . substr($code, 5);
        }

        return array_values($codes);
    }

    /**
     * Normalised code, or null when it cannot be a recovery code.
     */
    public static function normalize(string $input): ?string
    {
        $code = strtr(strtoupper((string) preg_replace('/[\s\-]/', '', $input)), ['O' => '0', 'I' => '1', 'L' => '1']);

        return strlen($code) === self::LENGTH && preg_match('/^[' . self::ALPHABET . ']+\z/', $code) === 1 ? $code : null;
    }

    public function hash(string $normalized): string
    {
        return hash_hmac('sha256', 'nowo-auth-kit-mfa-recovery|' . $normalized, $this->secret);
    }
}
