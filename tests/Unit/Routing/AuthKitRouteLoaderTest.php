<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Routing;

use Nowo\AuthKitBundle\Routing\AuthKitRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthKitRouteLoaderTest extends TestCase
{
    public function testLoadsConfiguredRoutes(): void
    {
        $loader = new AuthKitRouteLoader([
            'login'    => ['path' => '/login', 'name' => 'nowo_auth_kit_login'],
            'logout'   => ['path' => '/logout', 'name' => 'nowo_auth_kit_logout'],
            'register' => ['path' => '/register', 'name' => 'nowo_auth_kit_register'],
        ]);

        self::assertTrue($loader->supports('.', 'nowo_auth_kit'));

        $collection = $loader->load('.', 'nowo_auth_kit');

        $login    = $collection->get('nowo_auth_kit_login');
        $register = $collection->get('nowo_auth_kit_register');
        self::assertNotNull($login);
        self::assertNotNull($register);
        self::assertSame('/login', $login->getPath());
        self::assertSame('/register', $register->getPath());
    }

    public function testThrowsWhenLoadedTwice(): void
    {
        $loader = new AuthKitRouteLoader([
            'login'    => ['path' => '/login', 'name' => 'nowo_auth_kit_login'],
            'logout'   => ['path' => '/logout', 'name' => 'nowo_auth_kit_logout'],
            'register' => ['path' => '/register', 'name' => 'nowo_auth_kit_register'],
        ]);

        $loader->load('.', 'nowo_auth_kit');
        $this->expectException(RuntimeException::class);
        $loader->load('.', 'nowo_auth_kit');
    }
}
