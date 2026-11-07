<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ProgrammaticLoginAuthenticatorTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'none' => [[], null];
        yield 'one' => [['form_login'], null];
        yield 'one and remember-me' => [['login_link', 'remember_me'], null];
        yield 'both prefers form_login' => [['login_link', 'form_login'], 'form_login'];
        yield 'both with remember-me' => [['form_login', 'login_link', 'remember_me'], 'form_login'];
        yield 'login_link and a host one' => [['app_custom', 'login_link'], 'login_link'];
        yield 'two host ones keep the error of Symfony' => [['app_custom', 'app_other'], null];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('cases')]
    public function testName(array $names, ?string $expected): void
    {
        $factories = [];
        foreach ($names as $name) {
            $factories['security.authenticator.' . $name . '.main'] = static fn (): object => new stdClass();
        }

        self::assertSame($expected, (new ProgrammaticLoginAuthenticator(['main' => new ServiceLocator($factories)]))->nameFor('main'));
    }

    public function testUnknownFirewallAndAFirewallWithoutAuthenticators(): void
    {
        $resolver = new ProgrammaticLoginAuthenticator(['api' => null]);

        self::assertNull($resolver->nameFor('api'));
        self::assertNull($resolver->nameFor('nope'));
    }
}
