<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaAuthenticationSubscriber;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaSessionsSubscriber;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function array_keys;
use function str_contains;

/**
 * `profiles.*.mfa` (1.34): off by default (no service), required issuer and encryption, per-firewall subscribers,
 * route names, entity mapping.
 */
final class MfaConfigurationTest extends TestCase
{
    private const USER = 'App\\Entity\\User';

    public function testDefaultsRegisterNothing(): void
    {
        $container = $this->load([]);
        $profile   = $this->profile($container);
        self::assertFalse($profile['mfa']['enabled']);
        self::assertSame('exempt', $profile['mfa']['first_factors']['qr']);
        self::assertSame('required', $profile['mfa']['first_factors']['social']);
        self::assertSame('trusted', $profile['mfa']['remember_me']);
        self::assertSame(2592000, $profile['mfa']['trusted_devices']['lifetime']);
        self::assertSame(5, $profile['mfa']['trusted_devices']['max']);
        self::assertSame([], array_filter(array_keys($container->getDefinitions()), static fn (string $id): bool => str_contains($id, '\\Mfa\\')));
    }

    public function testEnabledRegistersTheServicesPerFirewall(): void
    {
        $container = $this->load(['mfa' => ['enabled' => true, 'issuer' => 'Acme', 'policy' => 'app.mfa_policy']]);
        self::assertTrue($container->hasDefinition(MfaManager::class));
        self::assertTrue($container->hasDefinition(MfaSessionsSubscriber::class));
        $subscriber = $container->getDefinition(MfaAuthenticationSubscriber::class . '.main');
        self::assertSame('main', $subscriber->getArgument('$firewall'));
        self::assertSame('security.event_dispatcher.main', $subscriber->getTag('kernel.event_listener')[0]['dispatcher']);
        self::assertTrue($container->getParameter('nowo_auth_kit.doctrine.register_mapping'));
    }

    public function testIssuerAndEncryptionAreRequired(): void
    {
        try {
            $this->load(['mfa' => ['enabled' => true]]);
            self::fail('The issuer is required.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('mfa.issuer', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doctrine-encrypt-bundle');
        $this->load(['mfa' => ['enabled' => true, 'issuer' => 'Acme']], ['NowoAuthKitBundle' => 'x']);
    }

    public function testEncryptionBundleSatisfiesTheCheck(): void
    {
        $container = $this->load(['mfa' => ['enabled' => true, 'issuer' => 'Acme']], ['NowoDoctrineEncryptBundle' => 'x']);
        self::assertTrue($container->hasDefinition(MfaManager::class));
    }

    public function testMappingFalseIsRefusedWithMfa(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mfa.enabled false');
        $this->load(['doctrine' => ['register_mapping' => false], 'mfa' => ['enabled' => true, 'issuer' => 'Acme']]);
    }

    public function testMfaRouteNamesMustBeUnique(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate route name "nowo_auth_kit_login"');
        $this->load(['mfa' => ['enabled' => true, 'issuer' => 'Acme', 'routes' => ['challenge' => ['name' => 'nowo_auth_kit_login']]]]);
    }

    public function testInvalidValuesAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['mfa' => ['required_roles' => ['admin']]]);
    }

    public function testInvalidRoutePathsAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['mfa' => ['routes' => ['challenge' => ['path' => '//evil']]]]);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string>|null $bundles
     */
    private function load(array $config, ?array $bundles = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        if ($bundles !== null) {
            $container->setParameter('kernel.bundles', $bundles);
        }
        if (isset($config['mfa']) && !isset($config['mfa']['require_encryption']) && $bundles === null) {
            $config['mfa']['require_encryption'] = false;
        }
        (new NowoAuthKitExtension())->load([['user_class' => self::USER] + $config], $container);

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
