<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\UxIconStubExtension;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\PasswordToggleBundle\NowoPasswordToggleBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function str_replace;
use function sys_get_temp_dir;
use function version_compare;

/**
 * Social and QR sign-in on a firewall chosen by the subclass (`authenticators()`), TwigBundle registered first, MFA on.
 */
abstract class ProgrammaticLoginTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new TwigBundle();
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoPasswordToggleBundle();
        yield new NowoAuthKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/' . str_replace('\\', '-', static::class) . '/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/' . str_replace('\\', '-', static::class) . '/' . $this->environment . '/log';
    }

    public function home(): Response
    {
        return new Response('home');
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function authenticators(): array;

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret'                => 'auth-kit-test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection'       => true,
            'form'                  => ['csrf_protection' => true],
            'validation'            => ['enabled' => true],
            'translator'            => ['enabled' => true, 'fallbacks' => ['en']],
            'router'                => ['utf8' => true],
            'php_errors'            => ['log' => false],
            'lock'                  => 'flock',
            'http_client'           => ['mock_response_factory' => VerifiedAdaProvider::class],
        ]);
        $frameworkVersion = (string) InstalledVersions::getVersion('symfony/framework-bundle');
        if (version_compare($frameworkVersion, '7.3', '>=') && version_compare($frameworkVersion, '8.0', '<')) {
            $container->loadFromExtension('framework', ['property_info' => ['with_constructor_extractor' => true]]);
        }
        $container->loadFromExtension('twig', ['strict_variables' => true]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/auth_kit.sqlite'],
            'orm'  => ['mappings' => ['KernelTest' => [
                'type'      => 'attribute',
                'is_bundle' => false,
                'dir'       => '%kernel.project_dir%/tests/Integration/Kernel/Entity',
                'prefix'    => 'Nowo\\AuthKitBundle\\Tests\\Integration\\Kernel\\Entity',
            ]]],
        ]);
        $container->loadFromExtension('security', [
            'password_hashers' => [KernelUser::class => 'plaintext'],
            'providers'        => ['users' => ['entity' => ['class' => KernelUser::class, 'property' => 'email']]],
            'firewalls'        => ['main' => $this->authenticators() + [
                'lazy'        => true,
                'provider'    => 'users',
                'logout'      => ['path' => 'nowo_auth_kit_logout'],
                'remember_me' => ['secret' => 'auth-kit-test'],
            ]],
        ]);
        $container->loadFromExtension('nowo_ui_kit', ['css_framework' => 'none']);
        $container->loadFromExtension('nowo_auth_kit', [
            'user_class'          => KernelUser::class,
            'registration_mode'   => 'disabled',
            'login_success_route' => 'kernel_home',
            'remember_me'         => ['enabled' => true],
            'social_login'        => ['mode' => 'enabled'],
            'qr_login'            => ['mode' => 'enabled'],
            'mfa'                 => ['enabled' => true, 'issuer' => 'Acme', 'require_encryption' => false],
        ]);
        $container->register('logger', NullLogger::class);
        $container->register(UxIconStubExtension::class)->addTag('twig.extension');
        $container->register(VerifiedAdaProvider::class)->setPublic(true);
        $container->register(StubAuthenticator::class);
        $container->register(OtherStubAuthenticator::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoAuthKitBundle/Resources/config/routing.yaml');
        $routes->add('kernel_home', '/home')->controller('kernel::home');
    }
}
