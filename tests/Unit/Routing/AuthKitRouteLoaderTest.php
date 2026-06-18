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
        $loader = new AuthKitRouteLoader($this->routes(), 'link', false, 'en', ['en', 'es']);

        self::assertTrue($loader->supports('.', 'nowo_auth_kit'));

        $collection = $loader->load('.', 'nowo_auth_kit');

        self::assertNotNull($collection->get('nowo_auth_kit_login'));
        self::assertSame('/login', $collection->get('nowo_auth_kit_login')->getPath());
        self::assertNotNull($collection->get('nowo_auth_kit_reset_password_request'));
        self::assertNotNull($collection->get('nowo_auth_kit_reset_password'));
        self::assertNull($collection->get('nowo_auth_kit_reset_password_code'));
    }

    public function testPrefixesRoutesWithLocaleWhenEnabled(): void
    {
        $loader     = new AuthKitRouteLoader($this->routes(), 'link', true, 'en', ['en', 'es']);
        $collection = $loader->load('.', 'nowo_auth_kit');
        $loginRoute = $collection->get('nowo_auth_kit_login');

        self::assertNotNull($loginRoute);
        self::assertSame('/{_locale}/login', $loginRoute->getPath());
        self::assertSame('en', $loginRoute->getDefault('_locale'));
        self::assertSame('en|es', $loginRoute->getRequirement('_locale'));
    }

    public function testLoadsCodeRouteWhenDeliveryIsCode(): void
    {
        $loader     = new AuthKitRouteLoader($this->routes(), 'code', false, 'en', ['en', 'es']);
        $collection = $loader->load('.', 'nowo_auth_kit');

        self::assertNull($collection->get('nowo_auth_kit_reset_password'));
        self::assertNotNull($collection->get('nowo_auth_kit_reset_password_code'));
    }

    public function testThrowsWhenLoadedTwice(): void
    {
        $loader = new AuthKitRouteLoader($this->routes(), 'both', false, 'en', ['en', 'es']);

        $loader->load('.', 'nowo_auth_kit');
        $this->expectException(RuntimeException::class);
        $loader->load('.', 'nowo_auth_kit');
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    private function routes(): array
    {
        return [
            'login'               => ['path' => '/login', 'name' => 'nowo_auth_kit_login'],
            'logout'              => ['path' => '/logout', 'name' => 'nowo_auth_kit_logout'],
            'register'            => ['path' => '/register', 'name' => 'nowo_auth_kit_register'],
            'reset_request'       => ['path' => '/reset-password', 'name' => 'nowo_auth_kit_reset_password_request'],
            'reset_password'      => ['path' => '/reset-password/reset/{token}', 'name' => 'nowo_auth_kit_reset_password'],
            'reset_password_code' => ['path' => '/reset-password/complete', 'name' => 'nowo_auth_kit_reset_password_code'],
        ];
    }
}
