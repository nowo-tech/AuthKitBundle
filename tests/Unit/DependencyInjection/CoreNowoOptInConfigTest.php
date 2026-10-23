<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Opt-in options added for hosts on legacy schemas (unlocalized route suffix, logout methods, reset check-email page
 * and token in session, Doctrine mapping and disabled-feature routes). Defaults keep the previous behaviour.
 */
final class CoreNowoOptInConfigTest extends TestCase
{
    public function testDefaultsKeepPreviousBehaviour(): void
    {
        $container = $this->load([]);

        self::assertSame('_unlocalized', $container->getParameter('nowo_auth_kit.locale.unlocalized_route_suffix'));
        self::assertFalse($container->getParameter('nowo_auth_kit.routing.skip_disabled_features'));
        self::assertTrue($container->getParameter('nowo_auth_kit.doctrine.register_mapping'));
        self::assertTrue($container->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));

        $routes = $container->getParameter('nowo_auth_kit.routes');
        self::assertIsArray($routes);
        self::assertSame(['GET'], $routes['logout']['methods']);

        $reset = $container->getParameter('nowo_auth_kit.password_reset');
        self::assertIsArray($reset);
        self::assertNull($reset['after_request_route']);
        self::assertFalse($reset['token_in_session']);
    }

    public function testCoreNowoStyleOptions(): void
    {
        $container = $this->load([
            'locale'         => ['in_path' => 'both', 'unlocalized' => 'serve', 'unlocalized_route_suffix' => '.default'],
            'routing'        => ['skip_disabled_features' => true],
            'doctrine'       => ['register_mapping' => false],
            'routes'         => ['logout' => ['methods' => 'POST']],
            'password_reset' => ['mode' => 'enabled', 'after_request_route' => 'web.security.check_email', 'token_in_session' => true],
        ]);

        self::assertSame('.default', $container->getParameter('nowo_auth_kit.locale.unlocalized_route_suffix'));
        self::assertTrue($container->getParameter('nowo_auth_kit.routing.skip_disabled_features'));
        self::assertFalse($container->getParameter('nowo_auth_kit.doctrine.register_mapping'));
        self::assertFalse($container->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));

        $routes = $container->getParameter('nowo_auth_kit.routes');
        self::assertIsArray($routes);
        self::assertSame(['POST'], $routes['logout']['methods']);

        $reset = $container->getParameter('nowo_auth_kit.password_reset');
        self::assertIsArray($reset);
        self::assertSame('web.security.check_email', $reset['after_request_route']);
        self::assertTrue($reset['token_in_session']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function autoMappingCases(): iterable
    {
        yield 'nothing enabled' => [[], false];
        yield 'social enabled' => [['social_login' => ['mode' => 'enabled']], true];
        yield 'qr enabled' => [['qr_login' => ['mode' => 'enabled']], true];
    }

    /**
     * @param array<string, mixed> $profile
     */
    #[DataProvider('autoMappingCases')]
    public function testAutoMappingFollowsSocialAndQrLogin(array $profile, bool $mapped): void
    {
        $container = $this->load(['doctrine' => ['register_mapping' => 'auto']] + $profile);

        self::assertSame($mapped, $container->getParameter('nowo_auth_kit.doctrine.register_mapping'));
        self::assertSame($mapped, $container->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));
    }

    public function testMappingOffRejectsSocialOrQrLogin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('register_mapping: false');

        $this->load(['doctrine' => ['register_mapping' => false], 'qr_login' => ['mode' => 'enabled']]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'empty suffix' => [['locale' => ['unlocalized_route_suffix' => '']]];
        yield 'suffix with slash' => [['locale' => ['unlocalized_route_suffix' => '/x']]];
        yield 'logout method' => [['routes' => ['logout' => ['methods' => ['DELETE']]]]];
        yield 'no logout method' => [['routes' => ['logout' => ['methods' => []]]]];
        yield 'register_mapping' => [['doctrine' => ['register_mapping' => 'sometimes']]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidValues(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load($config);
    }

    public function testPrependOptsOutOfDoctrineAutoMappingOnTheAutoMappedManager(): void
    {
        $container = $this->containerWithDoctrine([
            ['orm' => ['entity_managers' => ['legacy' => ['auto_mapping' => false], 'main' => ['auto_mapping' => true]]]],
        ]);
        $container->prependExtensionConfig('nowo_auth_kit', ['doctrine' => ['register_mapping' => false]]);

        (new NowoAuthKitExtension())->prepend($container);

        self::assertSame(
            ['orm' => ['entity_managers' => ['main' => ['mappings' => ['NowoAuthKitBundle' => ['mapping' => false]]]]]],
            $container->getExtensionConfig('doctrine')[0],
        );
    }

    public function testPrependUsesTheDefaultEntityManagerOfTheShortcutLayout(): void
    {
        $container = $this->containerWithDoctrine([['orm' => ['auto_mapping' => true]], ['orm' => ['default_entity_manager' => 'app']]]);
        $container->prependExtensionConfig('nowo_auth_kit', ['profiles' => ['admin' => ['qr_login' => ['mode' => 'disabled']], 'other' => null], 'doctrine' => ['register_mapping' => 'auto']]);

        (new NowoAuthKitExtension())->prepend($container);

        self::assertSame(
            ['orm' => ['entity_managers' => ['app' => ['mappings' => ['NowoAuthKitBundle' => ['mapping' => false]]]]]],
            $container->getExtensionConfig('doctrine')[0],
        );
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, array<string, mixed>}>
     */
    public static function prependSkipCases(): iterable
    {
        yield 'mapping kept by default' => [[['orm' => ['auto_mapping' => true]]], []];
        yield 'auto with qr enabled' => [[['orm' => ['auto_mapping' => true]]], ['doctrine' => ['register_mapping' => 'auto'], 'qr_login' => ['mode' => 'enabled']]];
        yield 'host maps the bundle' => [[['orm' => ['mappings' => ['NowoAuthKitBundle' => ['mapping' => true]]]]], ['doctrine' => ['register_mapping' => false]]];
        yield 'host maps the bundle on a manager' => [[['orm' => ['entity_managers' => ['x' => 'invalid', 'default' => ['mappings' => ['NowoAuthKitBundle' => ['mapping' => true]]]]]]], ['doctrine' => ['register_mapping' => false]]];
        yield 'dbal only' => [[['dbal' => ['url' => 'sqlite:///:memory:']]], ['doctrine' => ['register_mapping' => false]]];
    }

    /**
     * @param list<array<string, mixed>> $doctrine
     * @param array<string, mixed> $authKit
     */
    #[DataProvider('prependSkipCases')]
    public function testPrependLeavesDoctrineAlone(array $doctrine, array $authKit): void
    {
        $container = $this->containerWithDoctrine($doctrine);
        if ($authKit !== []) {
            $container->prependExtensionConfig('nowo_auth_kit', $authKit);
        }

        (new NowoAuthKitExtension())->prepend($container);

        self::assertSame($doctrine, $container->getExtensionConfig('doctrine'));
    }

    public function testPrependWithoutDoctrineExtensionIsANoOp(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('nowo_auth_kit', ['doctrine' => ['register_mapping' => false]]);

        (new NowoAuthKitExtension())->prepend($container);

        self::assertFalse($container->hasExtension('doctrine'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoAuthKitExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function containerWithDoctrine(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'doctrine';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
        foreach (array_reverse($configs) as $config) {
            $container->prependExtensionConfig('doctrine', $config);
        }

        return $container;
    }
}
