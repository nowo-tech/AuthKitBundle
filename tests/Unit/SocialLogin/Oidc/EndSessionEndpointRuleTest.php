<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\EndSessionEndpointRule;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcDiscovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 2.8.0 (audit ZDC-L1): the browser-side rule of `end_session_endpoint`, one case per probe value of the audit.
 */
final class EndSessionEndpointRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        // Audit probes.
        yield 'short IPv4 loopback' => ['https://127.1/x'];
        yield 'hex IPv4 loopback' => ['https://0x7f.0.0.1/x'];
        yield 'bracketed IPv6 loopback' => ['https://[::1]/x'];
        yield 'single-label host' => ['https://intranet/x'];
        yield 'CR LF' => ["https://idp.example.com/x\r\nSet-Cookie: a=b"];
        yield 'space' => ['https://idp.example.com/x y'];
        yield 'double quote' => ['https://idp.example.com/x"y'];
        yield 'less than' => ['https://idp.example.com/x<y'];
        // Same family.
        yield 'greater than' => ['https://idp.example.com/x>y'];
        yield 'backslash' => ['https://idp.example.com\\@evil.example/x'];
        yield 'caret' => ['https://idp.example.com/x^y'];
        yield 'backtick' => ['https://idp.example.com/x`y'];
        yield 'braces' => ['https://idp.example.com/x{y}'];
        yield 'pipe' => ['https://idp.example.com/x|y'];
        yield 'tab' => ["https://idp.example.com/x\ty"];
        yield 'DEL' => ["https://idp.example.com/x\x7Fy"];
        yield 'raw non-ASCII' => ["https://idp.example.com/\xC3\xA9"];
        yield 'decimal IPv4' => ['https://2130706433/x'];
        yield 'octal IPv4' => ['https://0177.0.0.1/x'];
        yield 'hex last label' => ['https://idp.0x7f/x'];
        yield 'numeric last label' => ['https://idp.example.123/x'];
        yield 'private IPv4' => ['https://10.0.0.5/x'];
        yield 'link-local IPv6' => ['https://[fe80::1]/x'];
        yield 'IPv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/x'];
        yield 'not an IPv6 in brackets' => ['https://[idp.example.com]/x'];
        yield 'localhost' => ['https://localhost/x'];
        yield 'sub-domain of localhost' => ['https://idp.localhost/x'];
        yield 'odd host characters' => ['https://idp%2eexample.com/x'];
        yield 'empty label' => ['https://idp..example.com/x'];
        yield 'plain http' => ['http://idp.example.com/x'];
        yield 'credentials' => ['https://user:pass@idp.example.com/x'];
        yield 'fragment' => ['https://idp.example.com/x#f'];
        yield 'unparsable' => ['https:///x'];
        yield 'no host' => ['https:/x'];
        yield 'empty' => [''];
        yield 'not a string' => [['https://idp.example.com/x']];
        yield 'too long' => ['https://idp.example.com/' . str_repeat('a', OidcDiscovery::MAX_OPTIONAL_ENDPOINT_LENGTH)];
    }

    #[DataProvider('refused')]
    public function testRefused(mixed $url): void
    {
        self::assertFalse(EndSessionEndpointRule::isSafe($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'host name' => ['https://idp.example.com/logout'];
        yield 'query and port' => ['https://login.example.com:8443/tenant-1/oauth2/v2.0/logout?p=b2c_1_signin'];
        yield 'trailing dot' => ['https://idp.example.com./logout'];
        yield 'upper case and punycode' => ['https://IDP.xn--bcher-kva.example/logout'];
        yield 'public IPv4' => ['https://8.8.8.8/logout'];
        yield 'public IPv6' => ['https://[2001:4860:4860::8888]/logout'];
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $url): void
    {
        self::assertTrue(EndSessionEndpointRule::isSafe($url));
    }
}
