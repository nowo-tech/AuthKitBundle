<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

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
}
