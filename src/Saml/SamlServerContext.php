<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use OneLogin\Saml2\Utils;

use function array_key_exists;
use function parse_url;
use function strtolower;

/**
 * onelogin/php-saml computes the URL it compares `Destination` and `Recipient` with from its own static host / port /
 * // @igor-ignore - Request-scoped superglobal pinned for this call only and restored in finally.
 * protocol / base path and from `$_SERVER` (REQUEST_URI, SCRIPT_NAME). Behind a proxy, under a test kernel or in a
 * FrankenPHP worker those can differ from the ACS URL AuthKit generated. {@see self::run()} pins them to the ACS URL
 * // @igor-ignore - Request-scoped superglobal pinned for this call only and restored in finally.
 * for one call and restores `$_SERVER` and resets the statics afterwards, whatever happens (worker-safe: nothing
 * survives the call; a PHP thread runs one request at a time). AuthKit re-checks both values for exact equality after
 * the library ({@see SamlResponseValidator}).
 *
 * @internal
 */
final class SamlServerContext
{
    private const SERVER_KEYS = ['REQUEST_URI', 'QUERY_STRING', 'SCRIPT_NAME', 'PATH_INFO'];

    /**
     * @template T
     *
     * @param string $url the absolute ACS URL
     * @param callable(): T $call
     *
     * @return T
     */
    public static function run(string $url, callable $call): mixed
    {
        /** @var array{scheme?: string, host?: string, port?: int, path?: string} $parts */
        $parts  = (array) parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $path   = $parts['path'] ?? '/';
        $saved  = [];
        foreach (self::SERVER_KEYS as $key) {
            // @igor-ignore - Request-scoped superglobal pinned for this call only and restored in finally.
            $saved[$key] = array_key_exists($key, $_SERVER) ? [$_SERVER[$key]] : null;
        }
        $proxyVars = Utils::getProxyVars();

        try {
            Utils::setProxyVars(false);
            Utils::setBaseURL('');
            Utils::setSelfProtocol($scheme);
            Utils::setSelfHost($parts['host'] ?? '');
            Utils::setSelfPort($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            // @igor-ignore - Request-scoped superglobal pinned for this call only and restored in finally.
            $_SERVER['REQUEST_URI'] = $path;
            // @igor-ignore - Request-scoped superglobal pinned for this call only and restored in finally.
            $_SERVER['SCRIPT_NAME'] = $path;
            // @igor-ignore - Request-scoped superglobal pinned for this call only and restored in finally.
            unset($_SERVER['QUERY_STRING'], $_SERVER['PATH_INFO']);

            return $call();
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    // @igor-ignore - Request-scoped superglobal pinned for this call only and restored in finally.
                    unset($_SERVER[$key]);
                } else {
                    // @igor-ignore - Request-scoped superglobal pinned for this call only and restored in finally.
                    $_SERVER[$key] = $value[0];
                }
            }
            Utils::setBaseURL('');
            Utils::setProxyVars($proxyVars);
        }
    }
}
