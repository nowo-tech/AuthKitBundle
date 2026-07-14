<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
        $embed = $this->container->getParameter('nowo_auth_kit.embed');
        self::assertIsArray($embed);
        self::assertSame('disabled', $embed['mode']);
        self::assertTrue($this->container->hasDefinition(\Nowo\AuthKitBundle\Controller\LoginController::class));
        self::assertTrue($this->container->hasDefinition(\Nowo\AuthKitBundle\Security\RegistrationGate::class));
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
}
