<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration;

use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Integration tests: bundle extension loads services from default configuration.
 *
 * @covers \Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension
 */
final class AuthKitBundleIntegrationTest extends TestCase
{
    public function testExtensionLoadsDefaultConfiguration(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuthKitExtension())->load([['user_class' => 'App\\Entity\\User']], $container);

        self::assertTrue($container->hasParameter('nowo_auth_kit.user_class'));
        self::assertSame('App\\Entity\\User', $container->getParameter('nowo_auth_kit.user_class'));
    }

    public function testBundleRegistersExtensionAlias(): void
    {
        $bundle = new NowoAuthKitBundle();
        self::assertSame('nowo_auth_kit', $bundle->getContainerExtension()->getAlias());
    }
}
