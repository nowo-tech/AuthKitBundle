<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\PasswordToggleBundle\NowoPasswordToggleBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function sys_get_temp_dir;

/**
 * Real kernel for the auth pages: FrameworkBundle (forms, CSRF, sessions), SecurityBundle (form_login with the
 * AuthKit login form fields), Doctrine (SQLite file in the kernel cache dir), FormKit, UiKit and AuthKit with
 * password reset (link + code) enabled.
 */
class AuthKitTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
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
        // One directory per kernel class and environment: never shared with another kernel of the same process.
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/' . $this->environment . '/log';
    }

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
        ]);
        // Installed version, read at runtime: the Symfony minor differs per CI cell.
        $frameworkVersion = (string) InstalledVersions::getVersion('symfony/framework-bundle');
        if (version_compare($frameworkVersion, '7.3', '>=') && version_compare($frameworkVersion, '8.0', '<')) {
            // REQ-SF-005: Symfony 7.3/7.4 deprecate leaving this option implicit (its default changes in 8.0).
            $container->loadFromExtension('framework', ['property_info' => ['with_constructor_extractor' => true]]);
        }
        $container->loadFromExtension('twig', ['strict_variables' => true]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/auth_kit.sqlite'],
            'orm'  => [
                'mappings' => [
                    'KernelTest' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => '%kernel.project_dir%/tests/Integration/Kernel/Entity',
                        'prefix'    => 'Nowo\\AuthKitBundle\\Tests\\Integration\\Kernel\\Entity',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('security', [
            'password_hashers' => [KernelUser::class => 'plaintext'],
            'providers'        => ['users' => ['entity' => ['class' => KernelUser::class, 'property' => 'email']]],
            'firewalls'        => [
                'main' => [
                    'lazy'       => true,
                    'provider'   => 'users',
                    'form_login' => [
                        'login_path'          => 'nowo_auth_kit_login',
                        'check_path'          => 'nowo_auth_kit_login',
                        'default_target_path' => 'kernel_home',
                        'enable_csrf'         => true,
                        'username_parameter'  => 'login_form[_username]',
                        'password_parameter'  => 'login_form[_password]',
                        'csrf_parameter'      => 'login_form[_csrf_token]',
                        'csrf_token_id'       => 'authenticate',
                    ],
                    'logout' => ['path' => 'nowo_auth_kit_logout'],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_ui_kit', ['css_framework' => 'none']);
        $container->loadFromExtension('nowo_auth_kit', [
            'user_class'          => KernelUser::class,
            'registration_mode'   => 'always',
            'login_success_route' => 'kernel_home',
            'password_reset'      => [
                'mode'                => 'enabled',
                'delivery'            => 'both',
                'token_field'         => 'passwordResetToken',
                'token_expires_field' => 'passwordResetExpiresAt',
            ],
            'magic_login' => ['mode' => 'enabled'],
            'qr_login'    => ['mode' => 'enabled'],
            'web_ui'      => ['brand' => 'Acme'],
        ]);

        $container->register('logger', NullLogger::class);
        $container->register(UxIconStubExtension::class)->addTag('twig.extension');
        $container->register(CapturingPasswordResetNotifier::class)->setPublic(true);
        $container->setAlias(PasswordResetNotifierInterface::class, CapturingPasswordResetNotifier::class)->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoAuthKitBundle/Resources/config/routing.yaml');
        // REQ-SF-004: the Symfony 7.3+ recipe import must not register the AuthKit controller attributes again.
        $routes->import('routing.controllers');
        $routes->add('kernel_home', '/home')
            ->controller(RedirectController::class . '::urlRedirectAction')
            ->defaults(['path' => '/login', 'permanent' => false]);
    }
}
