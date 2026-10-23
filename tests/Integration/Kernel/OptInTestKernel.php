<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

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
 * Kernel with every opt-in of the legacy-host options on (core-nowo style): Doctrine auto_mapping with the AuthKit
 * entities opted out, disabled features without routes, ".default" bare route names, POST-only CSRF logout, reset
 * request redirected to a host check-email page and the reset token kept in the session.
 */
final class OptInTestKernel extends Kernel
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
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/opt-in/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/opt-in/' . $this->environment . '/log';
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
        $container->loadFromExtension('twig', ['strict_variables' => true]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/auth_kit.sqlite'],
            'orm'  => [
                'auto_mapping' => true,
                'mappings'     => [
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
                    'logout' => ['path' => 'nowo_auth_kit_logout', 'enable_csrf' => true, 'target' => 'kernel_home'],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_ui_kit', ['css_framework' => 'none']);
        $container->loadFromExtension('nowo_auth_kit', [
            'user_class'          => KernelUser::class,
            'registration_mode'   => 'disabled',
            'login_success_route' => 'kernel_home',
            'locale'              => ['in_path' => 'both', 'unlocalized' => 'serve', 'default' => 'en', 'enabled' => ['en', 'es'], 'unlocalized_route_suffix' => '.default'],
            'routing'             => ['skip_disabled_features' => true],
            'doctrine'            => ['register_mapping' => false],
            'routes'              => ['logout' => ['methods' => ['POST']]],
            'embed'               => ['mode' => 'dropdown'],
            'password_reset'      => [
                'mode'                => 'enabled',
                'delivery'            => 'link',
                'after_request_route' => 'kernel_check_email',
                'token_in_session'    => true,
            ],
        ]);

        $container->register('logger', NullLogger::class);
        $container->register(UxIconStubExtension::class)->addTag('twig.extension');
        $container->register(CapturingPasswordResetNotifier::class)->setPublic(true);
        $container->setAlias(PasswordResetNotifierInterface::class, CapturingPasswordResetNotifier::class)->setPublic(true);
        $container->register(OptInPagesController::class)->setAutowired(true)->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoAuthKitBundle/Resources/config/routing.yaml');
        $routes->add('kernel_home', '/home')
            ->controller(RedirectController::class . '::urlRedirectAction')
            ->defaults(['path' => '/en/login', 'permanent' => false]);
        $routes->add('kernel_check_email', '/{_locale}/check-email')->controller(OptInPagesController::class . '::checkEmail');
        $routes->add('kernel_embed', '/embed')->controller(OptInPagesController::class . '::embed');
    }
}
