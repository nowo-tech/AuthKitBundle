<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\EnterpriseOidc;

use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestStart;
use Nowo\AuthKitBundle\Tests\Integration\Oidc\OidcTestKernel;
use Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * The OIDC kernel plus a host test handler (autoconfigured) and a host "Test sign-in" route (2.7.0), with
 * nowo-tech/doctrine-encrypt-bundle encrypting the client secrets (2.8.0: required for enterprise credentials).
 */
final class EnterpriseOidcTestKernel extends OidcTestKernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new NowoDoctrineEncryptBundle();
    }

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
        $container->loadFromExtension('nowo_doctrine_encrypt', [
            'generate_missing_key' => true,
            'profiles'             => ['default' => ['encryptor_class' => 'Halite', 'secret_directory_path' => '%kernel.cache_dir%/encrypt']],
        ]);
        $container->loadFromExtension('nowo_auth_kit', [
            'social_login' => ['rp_logout' => ['post_logout_redirect_uris' => ['/signed-out', '/']]],
        ]);
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
