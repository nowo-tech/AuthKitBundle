<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use Closure;

use function array_values;
use function dns_get_record;
use function gethostbynamel;
use function is_array;
use function is_string;

use const DNS_AAAA;

/**
 * System resolver: A records through gethostbynamel(), AAAA through dns_get_record(). Stateless.
 */
final class DnsHostResolver implements HostResolverInterface
{
    /** @var Closure(string): mixed */
    private readonly Closure $aaaaLookup;

    /**
     * @param (Closure(string): mixed)|null $aaaaLookup AAAA lookup (default dns_get_record(DNS_AAAA)); replaceable in tests
     */
    public function __construct(?Closure $aaaaLookup = null)
    {
        $this->aaaaLookup = $aaaaLookup ?? static fn (string $host): mixed => @dns_get_record($host, DNS_AAAA);
    }

    public function resolve(string $host): array
    {
        $addresses = [];
        $v4        = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $addresses[$ip] = $ip;
            }
        }
        $v6 = ($this->aaaaLookup)($host);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                $ip = is_array($record) ? ($record['ipv6'] ?? null) : null;
                if (is_string($ip) && $ip !== '') {
                    $addresses[$ip] = $ip;
                }
            }
        }

        return array_values($addresses);
    }
}
