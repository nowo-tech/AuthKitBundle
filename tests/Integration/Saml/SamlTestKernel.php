<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Saml;

use Nowo\AuthKitBundle\Saml\SamlIdpMetadataImporter;
use Nowo\AuthKitBundle\Saml\SamlSpKeyGenerator;
use Nowo\AuthKitBundle\Tests\Integration\EnterpriseOidc\EnterpriseOidcTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * The enterprise kernel (DoctrineEncrypt, test handler) with SAML on (2.10.0), a fixed public origin
 * (`absolute_url.base_uri`), just-in-time creation, a host callback step for `saml:stepped` and a host "Test sign-in"
 * route for SAML providers.
 */
final class SamlTestKernel extends EnterpriseOidcTestKernel
{
    public const ORIGIN = 'https://sp.example.com';

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/saml-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/saml-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('framework', ['lock' => 'flock']);
        $container->loadFromExtension('nowo_auth_kit', [
            'absolute_url' => ['base_uri' => self::ORIGIN],
            'social_login' => ['create_user_if_missing' => true],
            'saml'         => ['mode' => 'enabled'],
        ]);
        $container->register(RecordingSamlStep::class)->setAutoconfigured(true)->setAutowired(true)->setPublic(true);
        $container->register(SamlTestSignInController::class)->setAutowired(true)->setPublic(true)->addTag('controller.service_arguments');
        $container->register(RecordingSamlListener::class)->setAutoconfigured(true)->setPublic(true);
        $container->register(SignInFailureListener::class)->setAutoconfigured(true)->setPublic(true);
        $container->setAlias('test.saml_key_generator', SamlSpKeyGenerator::class)->setPublic(true);
        $container->setAlias('test.saml_metadata_importer', SamlIdpMetadataImporter::class)->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);
        $routes->add('host_saml_test', '/admin/saml-test/{provider}')->controller(SamlTestSignInController::class);
    }
}
