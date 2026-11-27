<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\EnterpriseOidc;

use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestStart;
use Nowo\AuthKitBundle\Tests\Integration\Oidc\OidcTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * The OIDC kernel plus a host test handler (autoconfigured) and a host "Test sign-in" route (2.7.0).
 */
final class EnterpriseOidcTestKernel extends OidcTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/enterprise-oidc-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/enterprise-oidc-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->register(RecordingTestHandler::class)->setAutoconfigured(true)->setPublic(true);
        $container->register(TestSignInController::class)->setAutowired(true)->setPublic(true)->addTag('controller.service_arguments');
        $container->setAlias('test.social_test_start', SocialLoginTestStart::class)->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);
        $routes->add('host_sso_test', '/admin/sso-test/{provider}')->controller(TestSignInController::class);
    }
}
