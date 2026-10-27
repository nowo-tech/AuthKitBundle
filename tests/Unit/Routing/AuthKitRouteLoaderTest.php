<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Routing;

use LogicException;
use Nowo\AuthKitBundle\Controller\MagicLoginCheckController;
use Nowo\AuthKitBundle\Controller\MagicLoginConfirmController;
use Nowo\AuthKitBundle\Controller\UnlocalizedLocaleRedirectController;
use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLoader;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

use function class_exists;
use function count;
use function explode;

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

    /**
     * REQ-SF-004: every route is the per-profile copy of its controller's #[Route]; the attribute path and name
     * are the routes.<key> configuration defaults, its methods and requirements are those of the loaded route.
     */
    public function testEveryRouteComesFromItsControllerRouteAttribute(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => ['default' => ['user_class' => TestUser::class, 'password_reset' => ['delivery' => 'both'], 'magic_login' => ['confirm_interstitial' => true]]],
        ]]);
        $collection = (new AuthKitRouteLoader($config['profiles'], false, 'en', ['en']))->load('.', 'nowo_auth_kit');
        $routes     = $config['profiles']['default']['routes'];

        self::assertCount(count($routes), $collection);

        foreach ($routes as $key => $route) {
            $loaded = $collection->get($route['name']);
            self::assertNotNull($loaded, $key);

            $controller = $loaded->getDefault('_controller');
            self::assertIsString($controller);
            [$class, $action] = explode('::', $controller);
            self::assertTrue(class_exists($class), $class);
            $attribute = AuthKitRouteLoader::routeAttribute($class, $action);

            self::assertSame($route['path'], $attribute->path, $key);
            self::assertSame($route['name'], $attribute->name, $key);
            self::assertSame($attribute->methods, $loaded->getMethods(), $key);
            self::assertSame($attribute->requirements, $loaded->getRequirements(), $key);
        }

        $check = AuthKitRouteLoader::routeAttribute(MagicLoginCheckController::class, 'check');
        self::assertSame($routes['magic_login_check']['path'], $check->path);
        self::assertSame($routes['magic_login_check']['name'], $check->name);
    }

    public function testAControllerActionWithoutRouteAttributeIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(UnlocalizedLocaleRedirectController::class . '::redirect() must declare a #[Route] attribute.');

        AuthKitRouteLoader::routeAttribute(UnlocalizedLocaleRedirectController::class, 'redirect');
    }

    public function testCanLoadTwiceInTheSameProcess(): void
    {
        $loader = new AuthKitRouteLoader($this->profiles('link'), 'both', 'en', ['en', 'es']);

        $first  = $loader->load('.', 'nowo_auth_kit');
        $second = $loader->load('.', 'nowo_auth_kit');

        self::assertNotSame($first, $second);
        self::assertSame(array_keys($first->all()), array_keys($second->all()));
        self::assertSame($first->get('nowo_auth_kit_login')?->getPath(), $second->get('nowo_auth_kit_login')?->getPath());
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

    public function testBothModeUsesTheConfiguredUnlocalizedSuffix(): void
    {
        $loader     = new AuthKitRouteLoader($this->profiles('link'), 'both', 'es', ['es', 'en'], 'serve', '.default');
        $collection = $loader->load('.', 'nowo_auth_kit');

        self::assertNotNull($collection->get('nowo_auth_kit_login'));
        self::assertNull($collection->get('nowo_auth_kit_login_unlocalized'));
        $bare = $collection->get('nowo_auth_kit_login.default');
        self::assertNotNull($bare);
        self::assertSame('/login', $bare->getPath());
        self::assertSame('es', $bare->getDefault('_locale'));
    }

    public function testLogoutIsGetOnlyByDefaultAndFollowsConfiguredMethods(): void
    {
        $default = (new AuthKitRouteLoader($this->profiles('link'), false, 'en', ['en']))->load('.', 'nowo_auth_kit');
        self::assertSame(['GET'], $default->get('nowo_auth_kit_logout')?->getMethods());

        $profiles                                           = $this->profiles('link');
        $profiles['default']['routes']['logout']['methods'] = ['post'];
        $post                                               = (new AuthKitRouteLoader($profiles, 'both', 'en', ['en'], 'serve'))->load('.', 'nowo_auth_kit');
        self::assertSame(['POST'], $post->get('nowo_auth_kit_logout')?->getMethods());
        self::assertSame(['POST'], $post->get('nowo_auth_kit_logout_unlocalized')?->getMethods());

        $profiles['default']['routes']['logout']['methods'] = [];
        $fallback                                           = (new AuthKitRouteLoader($profiles, false, 'en', ['en']))->load('.', 'nowo_auth_kit');
        self::assertSame(['GET'], $fallback->get('nowo_auth_kit_logout')?->getMethods());
    }

    public function testTokenInSessionMakesTheResetTokenOptional(): void
    {
        $profiles                                                  = $this->profiles('link');
        $profiles['default']['password_reset']['token_in_session'] = true;

        $route = (new AuthKitRouteLoader($profiles, false, 'en', ['en']))->load('.', 'nowo_auth_kit')->get('nowo_auth_kit_reset_password');

        self::assertNotNull($route);
        self::assertTrue($route->hasDefault('token'));
        self::assertNull($route->getDefault('token'));

        $default = (new AuthKitRouteLoader($this->profiles('link'), false, 'en', ['en']))->load('.', 'nowo_auth_kit')->get('nowo_auth_kit_reset_password');
        self::assertNotNull($default);
        self::assertFalse($default->hasDefault('token'));
    }

    public function testDisabledFeaturesKeepTheirRoutesUnlessSkipped(): void
    {
        $profiles                                 = $this->profiles('link');
        $profiles['default']['registration_mode'] = 'disabled';

        $kept = (new AuthKitRouteLoader($profiles, false, 'en', ['en']))->load('.', 'nowo_auth_kit');
        self::assertNotNull($kept->get('nowo_auth_kit_register'));
        self::assertNotNull($kept->get('nowo_auth_kit_reset_password_request'));
        self::assertNotNull($kept->get('nowo_auth_kit_magic_login_check'));
        self::assertNotNull($kept->get('nowo_auth_kit_social_login_start'));
        self::assertNotNull($kept->get('nowo_auth_kit_qr_login_start'));

        $skipped = (new AuthKitRouteLoader($profiles, 'both', 'en', ['en'], 'serve', '.default', true))->load('.', 'nowo_auth_kit');
        self::assertSame(
            ['nowo_auth_kit_login', 'nowo_auth_kit_login.default', 'nowo_auth_kit_logout', 'nowo_auth_kit_logout.default'],
            array_keys($skipped->all()),
        );
    }

    public function testSkipKeepsEnabledFeatures(): void
    {
        $profiles                                      = $this->profiles('link');
        $profiles['default']['registration_mode']      = 'always';
        $profiles['default']['password_reset']['mode'] = 'enabled';
        $profiles['default']['magic_login']['mode']    = 'enabled';
        $profiles['default']['social_login']['mode']   = 'enabled';
        $profiles['default']['qr_login']['mode']       = 'enabled';

        $collection = (new AuthKitRouteLoader($profiles, false, 'en', ['en'], 'redirect', '_unlocalized', true))->load('.', 'nowo_auth_kit');

        foreach (['register', 'reset_password_request', 'reset_password', 'magic_login_request', 'magic_login_check', 'social_login_start', 'social_login_check', 'qr_login_start', 'qr_login_deny'] as $route) {
            self::assertNotNull($collection->get('nowo_auth_kit_' . $route), $route);
        }
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
