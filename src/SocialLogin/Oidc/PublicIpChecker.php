<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use function filter_var;
use function inet_ntop;
use function inet_pton;
use function intdiv;
use function ord;
use function str_repeat;
use function strlen;
use function substr;

use const FILTER_VALIDATE_IP;

/**
 * Whether an address is publicly routable (SSRF guard of the OIDC fetches, 2.3.0).
 *
 * An explicit list instead of FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE alone: those flags vary between PHP versions
 * and miss carrier-grade NAT (100.64.0.0/10), benchmarking (198.18.0.0/15) and the IPv6 encodings of an IPv4 address
 * (IPv4-mapped, NAT64, 6to4, Teredo), which would let a host name pointing at `::ffff:127.0.0.1` reach loopback.
 *
 * Pure function of its argument: no state, safe in FrankenPHP worker mode (REQ-RUNTIME-002).
 */
final class PublicIpChecker
{
    /** @var list<array{0: string, 1: int}> */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.88.99.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /** @var list<array{0: string, 1: int}> */
    private const BLOCKED_V6 = [
        ['::', 96],          // unspecified, loopback and the deprecated IPv4-compatible block
        ['::ffff:0:0:0', 96], // IPv4-translated (RFC 2765), audit ZBP-I1
        ['64:ff9b::', 96],   // NAT64
        ['64:ff9b:1::', 48], // local-use NAT64
        ['100::', 64],       // discard
        ['2001::', 23],      // IETF protocol assignments (Teredo included)
        ['2001:db8::', 32],  // documentation
        ['2002::', 16],      // 6to4
        ['3fff::', 20],      // documentation (RFC 9637)
        ['fc00::', 7],       // unique local
        ['fe80::', 10],      // link local
        ['fec0::', 10],      // site local (deprecated)
        ['ff00::', 8],       // multicast
    ];

    public function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // filter_var() accepted it, so inet_pton() does too.
        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 4) {
            return !$this->inAny($packed, self::BLOCKED_V4);
        }

        // IPv4-mapped (::ffff:a.b.c.d): judged as the IPv4 address it carries.
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $v4 = inet_ntop(substr($packed, 12));

            return $v4 !== false && $this->isPublic($v4);
        }

        return !$this->inAny($packed, self::BLOCKED_V6);
    }

    /**
     * @param list<array{0: string, 1: int}> $ranges
     */
    private function inAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as [$network, $bits]) {
            if ($this->inRange($packed, (string) inet_pton($network), $bits)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $packed, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
