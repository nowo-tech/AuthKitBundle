<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function dirname;

final class TwigPathsPassTest extends TestCase
{
    public function testAddsBundleViewsPathToNativeLoader(): void
    {
        $loader    = new Definition(\Twig\Loader\FilesystemLoader::class);
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', $loader);

        (new TwigPathsPass())->process($container);

        $calls = $loader->getMethodCalls();
        self::assertSame('addPath', $calls[0][0]);
        self::assertSame('NowoAuthKitBundle', $calls[0][1][1]);
    }

    public function testPrependsApplicationOverridePathWhenPresent(): void
    {
        $projectDir   = sys_get_temp_dir() . '/auth-kit-twig-' . uniqid();
        $overridePath = $projectDir . '/templates/bundles/NowoAuthKitBundle';
        mkdir($overridePath, 0777, true);

        $loader    = new Definition(\Twig\Loader\FilesystemLoader::class);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setDefinition('twig.loader.native', $loader);

        (new TwigPathsPass())->process($container);

        self::assertSame('prependPath', $loader->getMethodCalls()[0][0]);

        rmdir($overridePath);
        rmdir(dirname($overridePath));
        rmdir(dirname($overridePath, 2));
        rmdir($projectDir);
    }

    public function testNoOpWhenTwigLoaderMissing(): void
    {
        $container = new ContainerBuilder();
        (new TwigPathsPass())->process($container);
        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    public function testUsesNativeLoaderDefinitionWhenRegisteredDirectly(): void
    {
        $loader    = new Definition(\Twig\Loader\FilesystemLoader::class);
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native', $loader);

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($loader->getMethodCalls());
    }

    public function testResolvesMultiHopNativeLoaderAliasChain(): void
    {
        $loader    = new Definition(\Twig\Loader\FilesystemLoader::class);
        $container = new ContainerBuilder();
        $container->setDefinition('custom.native.loader', $loader);
        $container->setAlias('intermediate.loader', 'custom.native.loader');
        $container->setAlias('twig.loader.native', 'intermediate.loader');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($loader->getMethodCalls());
    }

    public function testReturnsNullWhenAliasDoesNotResolveToDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias('twig.loader.native', 'missing.loader');

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('missing.loader'));
    }

    public function testResolvesNativeLoaderAliasChain(): void
    {
        $loader    = new Definition(\Twig\Loader\FilesystemLoader::class);
        $container = new ContainerBuilder();
        $container->setDefinition('custom.native.loader', $loader);
        $container->setAlias('twig.loader.native', 'custom.native.loader');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($loader->getMethodCalls());
    }
}
