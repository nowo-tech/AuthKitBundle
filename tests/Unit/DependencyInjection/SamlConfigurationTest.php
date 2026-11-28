<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Saml\Controller\SamlLoginController;
use Nowo\AuthKitBundle\Saml\SamlTestStart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `profiles.*.saml` (2.10.0): off by default (no service), the services and the entity mapping when enabled, and the
 * validation of the times and route paths.
 */
final class SamlConfigurationTest extends TestCase
{
    public function testDefaultsRegisterNothing(): void
    {
        $container = $this->load([]);
        $saml      = $this->profile($container)['saml'];
        self::assertSame('disabled', $saml['mode']);
        self::assertSame(60, $saml['clock_skew']);
        self::assertSame(600, $saml['request_lifetime']);
        self::assertSame('/login/saml/{provider}/acs', $saml['routes']['acs']['path']);
        self::assertSame('nowo_auth_kit_saml_metadata', $saml['routes']['metadata']['name']);
        self::assertFalse($container->hasDefinition(SamlLoginController::class));
    }

    public function testEnabledRegistersTheServicesAndMapsTheEntities(): void
    {
        $container = $this->load(['saml' => ['mode' => 'enabled']]);
        self::assertTrue($container->hasDefinition(SamlLoginController::class));
        self::assertTrue($container->getDefinition(SamlTestStart::class)->isPublic());
        self::assertTrue($container->getParameter('nowo_auth_kit.doctrine.register_mapping'));
    }

    public function testTheMappingIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('saml.mode');
        $this->load(['saml' => ['mode' => 'enabled'], 'doctrine' => ['register_mapping' => false]]);
    }

    public function testDuplicateRouteNamesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->load(['saml' => ['mode' => 'enabled', 'routes' => ['acs' => ['name' => 'nowo_auth_kit_login']]]]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'skew too large' => [['clock_skew' => 181]];
        yield 'negative skew' => [['clock_skew' => -1]];
        yield 'lifetime too short' => [['request_lifetime' => 59]];
        yield 'path without provider' => [['routes' => ['acs' => ['path' => '/login/saml/acs']]]];
        yield 'path without slash' => [['routes' => ['start' => ['path' => 'login/{provider}']]]];
        yield 'protocol-relative path' => [['routes' => ['metadata' => ['path' => '//evil.example/{provider}']]]];
        yield 'unknown mode' => [['mode' => 'on']];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRefused(array $settings): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['saml' => $settings]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new NowoAuthKitExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(ContainerBuilder $container): array
    {
        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = $container->getParameter('nowo_auth_kit.profiles');

        return $profiles['default'];
    }
}
