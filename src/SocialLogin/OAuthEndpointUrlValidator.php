<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use InvalidArgumentException;

use function filter_var;
use function parse_url;
use function sprintf;
use function strtolower;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;

/**
 * Rejects non-HTTPS and private/loopback OAuth endpoint URLs (SSRF hardening).
 */
final class OAuthEndpointUrlValidator
{
    /**
     * @throws InvalidArgumentException
     */
    public function assertSafeHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(sprintf('Social OAuth %s URL is invalid.', $label));
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new InvalidArgumentException(sprintf('Social OAuth %s URL must use HTTPS.', $label));
        }

        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '0.0.0.0') {
            throw new InvalidArgumentException(sprintf('Social OAuth %s URL host is not allowed.', $label));
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host)) {
                throw new InvalidArgumentException(sprintf('Social OAuth %s URL must not target a private or reserved IP.', $label));
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return true;
        }

        // IPv6: same flags
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && $ip !== '::1';
    }
}
