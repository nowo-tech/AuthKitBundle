<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Routing;

use Nowo\AuthKitBundle\Controller\MagicLoginCheckController;
use Nowo\AuthKitBundle\Controller\MagicLoginConfirmController;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLoader;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthKitRouteLoaderTest extends TestCase
{
    public function testLoadsConfiguredRoutes(): void
    {
        $loader = new AuthKitRouteLoader($this->profiles('link'), false, 'en', ['en', 'es']);

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
        $loader     = new AuthKitRouteLoader($this->profiles('link'), true, 'en', ['en', 'es']);
        $collection = $loader->load('.', 'nowo_auth_kit');
        $loginRoute = $collection->get('nowo_auth_kit_login');

        self::assertNotNull($loginRoute);
        self::assertSame('/{_locale}/login', $loginRoute->getPath());
        self::assertSame('en', $loginRoute->getDefault('_locale'));
        self::assertSame('en|es', $loginRoute->getRequirement('_locale'));
    }

    public function testBothModeRegistersLocalizedAndUnlocalizedRedirect(): void
    {
        $loader     = new AuthKitRouteLoader($this->profiles('link'), 'both', 'en', ['en', 'es'], 'redirect');
        $collection = $loader->load('.', 'nowo_auth_kit');

        $localized = $collection->get('nowo_auth_kit_login');
        $bare      = $collection->get('nowo_auth_kit_login_unlocalized');

        self::assertNotNull($localized);
        self::assertSame('/{_locale}/login', $localized->getPath());
        self::assertNotNull($bare);
        self::assertSame('/login', $bare->getPath());
        self::assertSame('nowo_auth_kit_login', $bare->getDefault('_auth_kit_canonical_route'));
    }

    public function testBothModeServeSetsDefaultLocaleOnBareRoute(): void
    {
        $loader     = new AuthKitRouteLoader($this->profiles('link'), 'both', 'en', ['en', 'es'], 'serve');
        $collection = $loader->load('.', 'nowo_auth_kit');
        $bare       = $collection->get('nowo_auth_kit_login_unlocalized');

        self::assertNotNull($bare);
        self::assertSame('/login', $bare->getPath());
        self::assertSame('en', $bare->getDefault('_locale'));
        self::assertNull($bare->getDefault('_auth_kit_canonical_route'));
    }

    public function testLoadsCodeRouteWhenDeliveryIsCode(): void
    {
        $loader     = new AuthKitRouteLoader($this->profiles('code'), false, 'en', ['en', 'es']);
        $collection = $loader->load('.', 'nowo_auth_kit');

        self::assertNull($collection->get('nowo_auth_kit_reset_password'));
        self::assertNotNull($collection->get('nowo_auth_kit_reset_password_code'));
    }

    public function testThrowsWhenLoadedTwice(): void
    {
        $loader = new AuthKitRouteLoader($this->profiles('both'), false, 'en', ['en', 'es']);

        $loader->load('.', 'nowo_auth_kit');
        $this->expectException(RuntimeException::class);
        $loader->load('.', 'nowo_auth_kit');
    }

    public function testMagicLoginCheckIsGetOnlyByDefault(): void
    {
        $loader     = new AuthKitRouteLoader($this->profiles('link'), false, 'en', ['en']);
        $collection = $loader->load('.', 'nowo_auth_kit');
        $route      = $collection->get('nowo_auth_kit_magic_login_check');

        self::assertNotNull($route);
        self::assertSame(['GET'], $route->getMethods());
        self::assertSame(MagicLoginCheckController::class . '::check', $route->getDefault('_controller'));
    }

    public function testMagicLoginConfirmInterstitialRegistersGetCheckAndPostConfirm(): void
    {
        $profiles                                                   = $this->profiles('link');
        $profiles['default']['magic_login']['confirm_interstitial'] = true;

        $loader     = new AuthKitRouteLoader($profiles, false, 'en', ['en']);
        $collection = $loader->load('.', 'nowo_auth_kit');
        $check      = $collection->get('nowo_auth_kit_magic_login_check');
        $confirm    = $collection->get('nowo_auth_kit_magic_login_confirm');

        self::assertNotNull($check);
        self::assertSame(['GET'], $check->getMethods());
        self::assertSame(MagicLoginConfirmController::class . '::check', $check->getDefault('_controller'));

        self::assertNotNull($confirm);
        self::assertSame(['POST'], $confirm->getMethods());
        self::assertSame(MagicLoginConfirmController::class . '::confirm', $confirm->getDefault('_controller'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function profiles(string $delivery): array
    {
        return [
            'default' => array_replace_recursive(
                ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
                [
                    'routes' => [
                        'login'               => ['path' => '/login', 'name' => 'nowo_auth_kit_login'],
                        'logout'              => ['path' => '/logout', 'name' => 'nowo_auth_kit_logout'],
                        'register'            => ['path' => '/register', 'name' => 'nowo_auth_kit_register'],
                        'reset_request'       => ['path' => '/reset-password', 'name' => 'nowo_auth_kit_reset_password_request'],
                        'reset_password'      => ['path' => '/reset-password/reset/{token}', 'name' => 'nowo_auth_kit_reset_password'],
                        'reset_password_code' => ['path' => '/reset-password/complete', 'name' => 'nowo_auth_kit_reset_password_code'],
                    ],
                    'password_reset' => ['delivery' => $delivery],
                ],
            ),
        ];
    }
}
