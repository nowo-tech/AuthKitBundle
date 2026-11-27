<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Webauthn\RelyingParty;

use Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function str_repeat;

/**
 * The relying party rules of 2.6.0: a registrable RP id, https origins (http://localhost in development) on the RP id
 * or a sub-domain of it.
 */
final class WebAuthnRelyingPartyValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function rpIds(): iterable
    {
        yield 'domain' => ['example.com', true];
        yield 'sub-domain' => ['login.example.co.uk', true];
        yield 'localhost' => ['localhost', true];
        yield 'sub-domain of localhost' => ['app.localhost', true];
        yield 'hyphen and digits' => ['my-app2.example.com', true];
        yield 'empty' => ['', false];
        yield 'scheme' => ['https://example.com', false];
        yield 'port' => ['example.com:8443', false];
        yield 'path' => ['example.com/login', false];
        yield 'upper case' => ['Example.com', false];
        yield 'trailing dot' => ['example.com.', false];
        yield 'trailing new line' => ["example.com\n", false];
        yield 'IPv4' => ['192.168.1.10', false];
        yield 'IPv6' => ['::1', false];
        yield 'bracketed IPv6' => ['[::1]', false];
        yield 'numeric last label' => ['host.123', false];
        yield 'single label' => ['intranet', false];
        yield 'top-level domain' => ['com', false];
        yield 'leading hyphen' => ['-example.com', false];
        yield 'label too long' => [str_repeat('a', 64) . '.com', false];
        yield 'user info' => ['user@example.com', false];
        yield 'wildcard' => ['*.example.com', false];
    }

    #[DataProvider('rpIds')]
    public function testTheRpIdRules(string $rpId, bool $valid): void
    {
        self::assertSame($valid, WebAuthnRelyingPartyValidator::isValidRpId($rpId));
    }

    /**
     * @return iterable<string, array{string, list<string>, bool, list<string>}>
     */
    public static function relyingParties(): iterable
    {
        yield 'the RP id origin' => ['example.com', ['https://example.com'], false, []];
        yield 'sub-domain origins with ports' => ['example.com', ['https://login.example.com', 'https://example.com:8443'], false, []];
        yield 'http localhost in development' => ['localhost', ['http://localhost:8000'], true, []];
        yield 'https localhost' => ['localhost', ['https://localhost'], false, []];
        yield 'http localhost outside development' => ['localhost', ['http://localhost:8000'], false, ['The origin "http://localhost:8000" is not https://host[:port].']];
        yield 'http on another host in development' => ['example.com', ['http://example.com'], true, ['The origin "http://example.com" is not https://host[:port] or http://localhost[:port].']];
        yield 'http 127.0.0.1' => ['localhost', ['http://127.0.0.1'], true, ['The origin "http://127.0.0.1" is not https://host[:port] or http://localhost[:port].']];
        yield 'another domain' => ['example.com', ['https://example.org'], false, ['The origin "https://example.org" is not on the RP id "example.com" or a sub-domain of it.']];
        yield 'look-alike suffix' => ['example.com', ['https://badexample.com'], false, ['The origin "https://badexample.com" is not on the RP id "example.com" or a sub-domain of it.']];
        yield 'parent of the RP id' => ['login.example.com', ['https://example.com'], false, ['The origin "https://example.com" is not on the RP id "login.example.com" or a sub-domain of it.']];
        yield 'path' => ['example.com', ['https://example.com/'], false, ['The origin "https://example.com/" is not https://host[:port].']];
        yield 'query' => ['example.com', ['https://example.com?x'], false, ['The origin "https://example.com?x" is not https://host[:port].']];
        yield 'user info' => ['example.com', ['https://user@example.com'], false, ['The origin "https://user@example.com" is not https://host[:port].']];
        yield 'port zero' => ['example.com', ['https://example.com:0'], false, ['The origin "https://example.com:0" is not https://host[:port].']];
        yield 'port too large' => ['example.com', ['https://example.com:65536'], false, ['The origin "https://example.com:65536" is not https://host[:port].']];
        yield 'upper case host' => ['example.com', ['https://Example.com'], false, ['The origin "https://Example.com" is not https://host[:port].']];
        yield 'other scheme' => ['example.com', ['ftp://example.com'], false, ['The origin "ftp://example.com" is not https://host[:port].']];
        yield 'trailing new line (JSON in the message)' => ['example.com', ["https://example.com\n"], false, ['The origin "https://example.com\\n" is not https://host[:port].']];
        yield 'explicit https default port' => ['example.com', ['https://example.com:443'], false, ['The origin "https://example.com:443" names the default port: leave it out.']];
        yield 'explicit http default port' => ['localhost', ['http://localhost:80'], true, ['The origin "http://localhost:80" names the default port: leave it out.']];
        yield 'non-default port 8443' => ['example.com', ['https://example.com:8443'], false, []];
        yield 'no origin' => ['example.com', [], false, ['No allowed origin.']];
        yield 'invalid RP id' => ['https://example.com', ['https://example.com'], false, ['The RP id "https://example.com" is not a registrable host name (lower case, no scheme, port or IP address).']];
    }

    /**
     * @param list<string> $origins
     * @param list<string> $expected
     */
    #[DataProvider('relyingParties')]
    public function testTheRelyingPartyRules(string $rpId, array $origins, bool $allowHttpLocalhost, array $expected): void
    {
        self::assertSame($expected, WebAuthnRelyingPartyValidator::violations($rpId, $origins, $allowHttpLocalhost));
    }

    public function testOneOriginAndTheRpIdMessage(): void
    {
        self::assertNull(WebAuthnRelyingPartyValidator::originViolation('https://login.example.com', 'example.com'));
        self::assertNull(WebAuthnRelyingPartyValidator::originViolation('https://anything.example.org', null), 'form only');
        self::assertSame('The origin "https://example.org" is not on the RP id "example.com" or a sub-domain of it.', WebAuthnRelyingPartyValidator::originViolation('https://example.org', 'example.com'));
        self::assertSame('The RP id "a\\u0000b" is not a registrable host name (lower case, no scheme, port or IP address).', WebAuthnRelyingPartyValidator::rpIdViolation("a\0b"));
    }

    public function testTooManyOrigins(): void
    {
        self::assertSame(
            ['More than 50 allowed origins.'],
            WebAuthnRelyingPartyValidator::violations('example.com', array_fill(0, WebAuthnRelyingPartyValidator::MAX_ORIGINS + 1, 'https://example.com')),
        );
    }

    public function testTheHostRelation(): void
    {
        self::assertTrue(WebAuthnRelyingPartyValidator::isHostOfRpId('example.com', 'example.com'));
        self::assertTrue(WebAuthnRelyingPartyValidator::isHostOfRpId('a.b.example.com', 'example.com'));
        self::assertFalse(WebAuthnRelyingPartyValidator::isHostOfRpId('notexample.com', 'example.com'));
        self::assertFalse(WebAuthnRelyingPartyValidator::isHostOfRpId('example.com', 'login.example.com'));
    }
}
