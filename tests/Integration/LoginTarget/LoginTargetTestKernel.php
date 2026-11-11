<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoginTarget;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Nowo\AuthKitBundle\Tests\Support\LoginTargetFixtureController;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * The auth pages kernel with protected pages and machine endpoints, the post-login target guard (opt-in, on here), a host
 * voter (plain PHP, DDD-style port) and the expired-session JSON 401 (opt-in, on here).
 */
class LoginTargetTestKernel extends AuthKitTestKernel
{
    protected const SESSION_EXPIRED = true;

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/login-target-' . (static::SESSION_EXPIRED ? '401' : '302') . '-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/login-target-' . (static::SESSION_EXPIRED ? '401' : '302') . '-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('security', [
            'access_control' => [
                ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ['path' => '^/(secret|api|chat|reports|docs)', 'roles' => 'ROLE_USER'],
            ],
        ]);
        $container->loadFromExtension('nowo_auth_kit', ['login_target' => ['enabled' => true], 'session_expired' => ['enabled' => static::SESSION_EXPIRED]]);
        $container->register(LoginTargetFixtureController::class)->setPublic(true)->addTag('controller.service_arguments');
        $container->register(DocsApiPageVoter::class)->setAutoconfigured(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);
        $c = LoginTargetFixtureController::class;
        $routes->add('lt_secret', '/secret')->controller($c . '::page')->methods(['GET']);
        $routes->add('lt_admin', '/admin/panel')->controller($c . '::page')->methods(['GET']);
        $routes->add('lt_api_poll', '/api/poll')->controller($c . '::json');
        $routes->add('lt_chat_feed', '/chat/feed')->controller($c . '::json');
        $routes->add('lt_report_export', '/reports/export')->controller($c . '::page')->options(['html' => false]);
        $routes->add('lt_docs_api', '/docs/api')->controller($c . '::page');
    }
}
