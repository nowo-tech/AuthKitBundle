<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\LoginThrottleRequiredPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class LoginThrottleRequiredPassTest extends TestCase
{
    public function testNoOpWhenParameterMissing(): void
    {
        (new LoginThrottleRequiredPass())->process(new ContainerBuilder());

        $this->expectNotToPerformAssertions();
    }

    public function testNoOpWhenNotRequired(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_auth_kit.login_throttle_required', false);

        (new LoginThrottleRequiredPass())->process($container);

        $this->expectNotToPerformAssertions();
    }

    public function testFailsWhenRequiredAndExtensionMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_auth_kit.login_throttle_required', true);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('login-throttle-bundle is not registered');

        (new LoginThrottleRequiredPass())->process($container);
    }

    public function testPassesWhenRequiredAndExtensionPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_auth_kit.login_throttle_required', true);
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'nowo_login_throttle';
            }
        });

        (new LoginThrottleRequiredPass())->process($container);

        $this->expectNotToPerformAssertions();
    }
}
