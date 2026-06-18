<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoAuthKitBundleTest extends TestCase
{
    public function testTranslationDomainConstant(): void
    {
        self::assertSame('NowoAuthKitBundle', NowoAuthKitBundle::TRANSLATION_DOMAIN);
    }

    public function testBuildRegistersTwigPathsPass(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuthKitBundle())->build($container);

        $passes = $container->getCompilerPassConfig()->getPasses();
        self::assertNotEmpty(array_filter($passes, static fn (\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface $pass): bool => $pass instanceof TwigPathsPass));
    }

    public function testGetContainerExtension(): void
    {
        $bundle = new NowoAuthKitBundle();
        self::assertInstanceOf(NowoAuthKitExtension::class, $bundle->getContainerExtension());
    }
}
