<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Security\LoginTarget\BackgroundTargetPathPreserver;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetGuard;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetPolicy;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetVoterInterface;
use Nowo\AuthKitBundle\Security\SessionExpired\SessionExpiredResponder;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

use function ltrim;

/**
 * 1.44 post-login target guard and expired-session answer: defaults, validation and service wiring.
 */
final class LoginTargetConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertFalse($config['login_target']['enabled'], 'Opt-in: a minor release keeps existing post-login redirects.');
        self::assertSame([], $config['login_target']['firewalls']);
        self::assertNull($config['login_target']['fallback_route']);
        self::assertNull($config['login_target']['fallback_provider']);
        self::assertSame(Configuration::DEFAULT_DENIED_PATH_PREFIXES, $config['login_target']['denied_path_prefixes']);
        self::assertSame(Configuration::DEFAULT_DENIED_PATH_PATTERNS, $config['login_target']['denied_path_patterns']);
        self::assertSame([], $config['login_target']['denied_routes']);
        self::assertSame(Configuration::DEFAULT_DENIED_ROUTE_PATTERNS, $config['login_target']['denied_route_patterns']);
        self::assertSame('html', $config['login_target']['route_option']);
        self::assertTrue($config['login_target']['require_route']);
        self::assertTrue($config['login_target']['check_controller_response']);
        self::assertTrue($config['login_target']['preserve_on_background_requests']);
        self::assertSame([
            'enabled'       => false,
            'firewalls'     => [],
            'header_name'   => 'X-Auth-Kit',
            'header_value'  => 'session-expired',
            'problem_type'  => 'about:blank',
            'problem_title' => 'Unauthorized',
            'code'          => 'session_expired',
        ], $config['session_expired']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'prefix without slash' => [['login_target' => ['denied_path_prefixes' => ['api']]]];
        yield 'root prefix' => [['login_target' => ['denied_path_prefixes' => ['/']]]];
        yield 'protocol relative prefix' => [['login_target' => ['denied_path_prefixes' => ['//x']]]];
        yield 'prefix not a string' => [['login_target' => ['denied_path_prefixes' => [12]]]];
        yield 'bad path pattern' => [['login_target' => ['denied_path_patterns' => ['#(#']]]];
        yield 'empty path pattern' => [['login_target' => ['denied_path_patterns' => ['']]]];
        yield 'bad route pattern' => [['login_target' => ['denied_route_patterns' => ['not a regex']]]];
        yield 'header name' => [['session_expired' => ['header_name' => 'X Auth']]];
        yield 'header name not a string' => [['session_expired' => ['header_name' => 12]]];
        yield 'header value' => [['session_expired' => ['header_value' => "a\nb"]]];
        yield 'header value not a string' => [['session_expired' => ['header_value' => true]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process($config);
    }

    public function testDefaultWiringGuardsEveryProfileFirewallAndLeavesThe401Off(): void
    {
        $container = $this->load([
            'default_profile' => 'web',
            'profiles'        => [
                'web'   => ['user_class' => TestUser::class, 'firewall' => 'main', 'login_success_route' => 'app_home'],
                'admin' => ['user_class' => 'App\\Entity\\Admin', 'firewall' => 'admin', 'routes' => $this->prefixedRoutes('admin_')],
                'staff' => ['user_class' => 'App\\Entity\\Staff', 'firewall' => 'main', 'login_success_route' => 'staff_home', 'routes' => $this->prefixedRoutes('staff_')],
            ],
            'login_target' => ['enabled' => true],
        ]);

        self::assertSame(['main', 'admin'], $container->getParameter('nowo_auth_kit.login_target.firewalls'));
        self::assertTrue($container->getParameter('nowo_auth_kit.login_target.enabled'));
        self::assertFalse($container->getParameter('nowo_auth_kit.session_expired.enabled'));
        $guard = $container->getDefinition(LoginTargetGuard::class);
        self::assertSame(['main' => 'app_home', 'admin' => null], $guard->getArgument('$fallbackRoutes'));
        self::assertNull($guard->getArgument('$fallbackProvider'));
        self::assertSame([['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'priority' => -40]], $guard->getTag('kernel.event_listener'));
        self::assertTrue($container->hasDefinition(BackgroundTargetPathPreserver::class));
        self::assertFalse($container->hasDefinition(SessionExpiredResponder::class));
        self::assertSame('html', $container->getDefinition(LoginTargetPolicy::class)->getArgument('$routeOption'));
        self::assertArrayHasKey(LoginTargetVoterInterface::class, $container->getAutoconfiguredInstanceof());
    }

    public function testEverythingConfigured(): void
    {
        $container = $this->load([
            'user_class'          => TestUser::class,
            'login_success_route' => 'app_home',
            'login_target'        => [
                'enabled'                         => true,
                'firewalls'                       => ['main', 'main', 'admin'],
                'fallback_route'                  => 'start',
                'fallback_provider'               => 'app.start_page',
                'denied_routes'                   => ['wiki_comments'],
                'route_option'                    => null,
                'require_route'                   => false,
                'check_controller_response'       => false,
                'preserve_on_background_requests' => false,
            ],
            'session_expired' => ['enabled' => true, 'firewalls' => ['main'], 'header_name' => 'X-Beacon-Auth'],
        ]);

        $guard = $container->getDefinition(LoginTargetGuard::class);
        self::assertSame(['main', 'admin'], $guard->getArgument('$firewalls'));
        self::assertSame(['main' => 'start', 'admin' => 'start'], $guard->getArgument('$fallbackRoutes'));
        self::assertEquals(new Reference('app.start_page'), $guard->getArgument('$fallbackProvider'));
        $policy = $container->getDefinition(LoginTargetPolicy::class);
        self::assertNull($policy->getArgument('$routeOption'));
        self::assertFalse($policy->getArgument('$requireRoute'));
        self::assertFalse($policy->getArgument('$checkControllerResponse'));
        self::assertSame(['wiki_comments'], $policy->getArgument('$deniedRoutes'));
        self::assertFalse($container->hasDefinition(BackgroundTargetPathPreserver::class));
        $responder = $container->getDefinition(SessionExpiredResponder::class);
        self::assertSame(['main'], $responder->getArgument('$firewalls'));
        self::assertSame('X-Beacon-Auth', $responder->getArgument('$headerName'));
    }

    public function testNothingIsWiredByDefault(): void
    {
        $container = $this->load(['user_class' => TestUser::class]);

        self::assertFalse($container->getParameter('nowo_auth_kit.login_target.enabled'));
        self::assertFalse($container->getParameter('nowo_auth_kit.session_expired.enabled'));
        self::assertFalse($container->hasDefinition(LoginTargetGuard::class));
        self::assertFalse($container->hasDefinition(BackgroundTargetPathPreserver::class));
        self::assertFalse($container->hasDefinition(SessionExpiredResponder::class));
        self::assertTrue($container->hasDefinition(LoginTargetPolicy::class));
    }

    public function testGuardCanBeTurnedOffWhileThe401DefaultsToTheProfileFirewalls(): void
    {
        $container = $this->load([
            'user_class'      => TestUser::class,
            'login_target'    => ['enabled' => false],
            'session_expired' => ['enabled' => true],
        ]);

        self::assertFalse($container->hasDefinition(LoginTargetGuard::class));
        self::assertFalse($container->hasDefinition(BackgroundTargetPathPreserver::class));
        self::assertTrue($container->hasDefinition(LoginTargetPolicy::class), 'The policy stays available to host code.');
        self::assertSame(['main'], $container->getDefinition(SessionExpiredResponder::class)->getArgument('$firewalls'));
    }

    /**
     * Every route of a profile renamed with a prefix (route names are unique across profiles).
     *
     * @return array<string, array{name: string, path: string}>
     */
    private function prefixedRoutes(string $prefix): array
    {
        $routes = [];
        foreach ((new Processor())->processConfiguration(new Configuration(), [['user_class' => TestUser::class]])['profiles']['default']['routes'] as $key => $route) {
            $routes[$key] = ['name' => $prefix . $route['name'], 'path' => '/' . $prefix . ltrim($route['path'], '/')];
        }

        return $routes;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [['user_class' => TestUser::class] + $config]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoAuthKitExtension())->load([$config], $container);

        return $container;
    }
}
