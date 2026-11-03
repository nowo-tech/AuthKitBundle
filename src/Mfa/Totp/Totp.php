<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Totp;

use InvalidArgumentException;

use function hash_equals;
use function hash_hmac;
use function intdiv;
use function ord;
use function pack;
use function preg_match;
use function random_bytes;
use function rawurlencode;
use function sprintf;
use function str_pad;
use function strlen;

use const STR_PAD_LEFT;

/**
 * RFC 6238 TOTP (HMAC-SHA1, the algorithm every authenticator app supports) with a configurable number of digits,
 * period and skew window. Stateless (worker safe); replay protection is the caller's (`$lastStep`).
 */
final readonly class Totp
{
    /** Secret size in bytes (160 bits, RFC 4226 §4 recommendation). */
    public const SECRET_BYTES = 20;

    public function __construct(
        private int $digits = 6,
        private int $period = 30,
        private int $window = 1,
    ) {
    }

    /**
     * A new random secret, Base32 encoded.
     */
    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(self::SECRET_BYTES));
    }

    public function step(int $timestamp): int
    {
        return intdiv($timestamp, $this->period);
    }

    /**
     * The code of a time step (RFC 4226 dynamic truncation).
     */
    public function code(string $secret, int $step): string
    {
        $key = Base32::decode($secret);
        if (strlen($key) < 10) {
            throw new InvalidArgumentException('A TOTP secret needs at least 80 bits.');
        }
        $hash   = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * The time step a code matches within the skew window, or null. A step not greater than `$lastStep` (already
     * used) never matches: replayed codes are refused. Every step of the window is compared in constant time.
     */
    public function verify(string $secret, string $code, int $timestamp, ?int $lastStep = null): ?int
    {
        if (preg_match('/^\d{' . $this->digits . '}\z/', $code) !== 1) {
            return null;
        }
        $current = $this->step($timestamp);
        $matched = null;
        for ($offset = -$this->window; $offset <= $this->window; ++$offset) {
            $step = $current + $offset;
            if (hash_equals($this->code($secret, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }

    /**
     * The `otpauth://` URI of the enrolment QR code. Never log, flash or cache it: it carries the secret.
     */
    public function provisioningUri(string $issuer, string $label, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($label),
            $secret,
            rawurlencode($issuer),
            $this->digits,
            $this->period,
        );
    }
}
