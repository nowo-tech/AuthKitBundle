<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use Nowo\AuthKitBundle\Saml\SamlServerContext;
use OneLogin\Saml2\Utils;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SamlServerContext::class)]
#[BackupGlobals(true)]
final class SamlServerContextTest extends TestCase
{
    public function testTheLibrarySeesTheAcsUrlForOneCallOnly(): void
    {
        $_SERVER['REQUEST_URI']  = '/index.php?x=1';
        $_SERVER['QUERY_STRING'] = 'x=1';
        unset($_SERVER['PATH_INFO'], $_SERVER['SCRIPT_NAME']);
        Utils::setProxyVars(true);

        $seen = SamlServerContext::run('https://sp.example.com:8443/login/saml/acme/acs', static fn (): array => [Utils::getSelfRoutedURLNoQuery(), Utils::getSelfURLNoQuery(), Utils::getProxyVars()]);
        self::assertSame(['https://sp.example.com:8443/login/saml/acme/acs', 'https://sp.example.com:8443/login/saml/acme/acs', false], $seen);
        self::assertSame('http://sp.example.com/acs', SamlServerContext::run('http://sp.example.com/acs', static fn (): string => Utils::getSelfRoutedURLNoQuery()));

        self::assertSame('/index.php?x=1', $_SERVER['REQUEST_URI']);
        self::assertSame('x=1', $_SERVER['QUERY_STRING']);
        self::assertArrayNotHasKey('SCRIPT_NAME', $_SERVER);
        self::assertArrayNotHasKey('PATH_INFO', $_SERVER);
        self::assertTrue(Utils::getProxyVars());
        self::assertNull(Utils::getBaseURLPath());
        Utils::setProxyVars(false);
    }

    public function testTheServerIsRestoredWhenTheCallFails(): void
    {
        $_SERVER['REQUEST_URI'] = '/before';

        try {
            SamlServerContext::run('https://sp.example.com/acs', static function (): never {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        self::assertSame('/before', $_SERVER['REQUEST_URI']);
    }
}
