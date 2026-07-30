<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class NowoAuthKitExtensionTest extends TestCase
{
    private NowoAuthKitExtension $extension;

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new NowoAuthKitExtension();
        $this->container = new ContainerBuilder();
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_auth_kit', $this->extension->getAlias());
    }

    public function testLoadSetsParametersAndServices(): void
    {
        $this->extension->load([[
            'user_class'        => 'App\\Entity\\User',
            'registration_mode' => 'always',
            'registration_role' => 'ROLE_ADMIN',
        ]], $this->container);

        self::assertSame('App\\Entity\\User', $this->container->getParameter('nowo_auth_kit.user_class'));
        self::assertSame('always', $this->container->getParameter('nowo_auth_kit.registration_mode'));
        self::assertSame('ROLE_ADMIN', $this->container->getParameter('nowo_auth_kit.registration_role'));
        $embed     = $this->container->getParameter('nowo_auth_kit.embed');
        $templates = $this->container->getParameter('nowo_auth_kit.templates');
        $css       = $this->container->getParameter('nowo_auth_kit.css');
        self::assertIsArray($embed);
        self::assertIsArray($templates);
        self::assertIsArray($css);
        self::assertSame('disabled', $embed['mode']);
        self::assertSame(
            ['@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'],
            $templates['form_theme'],
        );
        self::assertSame('nowo-auth-kit__button', $css['button_class']);
        self::assertTrue($this->container->hasDefinition(LoginController::class));
        self::assertTrue($this->container->hasDefinition(RegistrationGate::class));
        self::assertTrue($this->container->hasAlias(OutboundMailReadyCheckerInterface::class));
        self::assertSame(
            AlwaysOutboundMailReadyChecker::class,
            (string) $this->container->getAlias(OutboundMailReadyCheckerInterface::class),
        );
    }

    public function testLoadRejectsUnknownDefaultProfile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->extension->load([[
            'default_profile' => 'missing',
            'profiles'        => [
                'default' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]], $this->container);
    }

    public function testLoadRejectsDuplicateUserClasses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->extension->load([[
            'profiles' => [
                'default' => ['user_class' => 'App\\Entity\\User'],
                'admin'   => ['user_class' => 'App\\Entity\\User'],
            ],
        ]], $this->container);
    }

    public function testLoadRejectsDuplicateRouteNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->extension->load([[
            'profiles' => [
                'default' => [
                    'user_class' => 'App\\Entity\\User',
                    'routes'     => [
                        'login' => ['path' => '/login', 'name' => 'app_login'],
                    ],
                ],
                'admin' => [
                    'user_class' => 'App\\Entity\\Admin',
                    'routes'     => [
                        'login' => ['path' => '/admin/login', 'name' => 'app_login'],
                    ],
                ],
            ],
        ]], $this->container);
    }

    public function testLoadRejectsEmptyUserClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->extension->load([[
            'profiles' => [
                'default' => ['user_class' => ''],
            ],
        ]], $this->container);
    }

    public function testLoadUsesConfiguredOutboundMailReadyChecker(): void
    {
        $this->extension->load([[
            'user_class'                  => 'App\\Entity\\User',
            'outbound_mail_ready_checker' => 'app.auth_kit.mail_ready_checker',
        ]], $this->container);

        self::assertSame(
            'app.auth_kit.mail_ready_checker',
            (string) $this->container->getAlias(OutboundMailReadyCheckerInterface::class),
        );
    }

    public function testPrependRegistersAuthKitAssetPackage(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createMock(ExtensionInterface::class));
        $container->registerExtension($this->createFrameworkExtensionStub());

        $this->extension->prepend($container);

        self::assertSame(
            [['assets' => ['packages' => ['nowo_auth_kit' => ['base_path' => '/bundles/nowoauthkit']]]]],
            $container->getExtensionConfig('framework'),
        );
    }

    public function testPrependSkipsWhenFrameworkExtensionIsMissing(): void
    {
        $container = new ContainerBuilder();

        $this->extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('framework'));
    }

    private function createFrameworkExtensionStub(): ExtensionInterface
    {
        return new class implements ExtensionInterface {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getNamespace(): string
            {
                return 'http://example.com/schema/dic/framework';
            }

            public function getXsdValidationBasePath(): string
            {
                return __DIR__;
            }

            public function getAlias(): string
            {
                return 'framework';
            }
        };
    }
}
