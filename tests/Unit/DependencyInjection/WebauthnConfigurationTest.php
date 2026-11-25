<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\MfaLockRequiredPass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Webauthn\Controller\WebauthnMfaController;
use Nowo\AuthKitBundle\Webauthn\PersonalData\WebauthnPersonalDataEraser;
use Nowo\AuthKitBundle\Webauthn\PersonalData\WebauthnPersonalDataExportProvider;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function array_keys;
use function str_contains;

/**
 * `profiles.*.webauthn` (2.4.0): off by default (no service, no route), validation of the RP id, origins and route
 * paths, the HMAC key, the services and the second-step controller, entity mapping, the lock requirement and the
 * personal-data hooks.
 */
final class WebauthnConfigurationTest extends TestCase
{
    private const USER = 'App\\Entity\\User';

    public function testDefaultsRegisterNothing(): void
    {
        $container = $this->load([]);
        $webauthn  = $this->profile($container)['webauthn'];
        self::assertSame('disabled', $webauthn['mode']);
        self::assertNull($webauthn['rp_id']);
        self::assertNull($webauthn['hash_secret']);
        self::assertSame(10, $webauthn['max_credentials']);
        self::assertSame(60000, $webauthn['timeout_ms']);
        self::assertSame('none', $webauthn['attestation']);
        self::assertSame(['limit' => 20, 'window' => 300], $webauthn['rate_limit']['assert']);
        self::assertSame('/login/webauthn/options', $webauthn['routes']['login_options']['path']);
        self::assertSame('exempt', $this->profile($container)['mfa']['first_factors']['webauthn']);
        self::assertSame([], array_filter(array_keys($container->getDefinitions()), static fn (string $id): bool => str_contains($id, '\\Webauthn\\Webauthn') || str_contains($id, '\\Webauthn\\Controller')));
    }

    public function testEnabledRegistersTheServices(): void
    {
        $container = $this->load(['webauthn' => ['mode' => 'enabled']]);
        self::assertTrue($container->hasDefinition(WebauthnManager::class));
        self::assertFalse($container->hasDefinition(WebauthnMfaController::class), 'No second step without MFA.');
        self::assertSame('%kernel.secret%', $this->profile($container)['webauthn']['hash_secret']);
        self::assertTrue($container->getParameter('nowo_auth_kit.doctrine.register_mapping'));
        self::assertTrue($container->hasDefinition(WebauthnPersonalDataEraser::class));
        self::assertTrue($container->hasDefinition(WebauthnPersonalDataExportProvider::class));

        $mfa = $this->load(['webauthn' => ['mode' => 'enabled'], 'mfa' => ['enabled' => true, 'issuer' => 'Acme', 'require_encryption' => false]]);
        self::assertTrue($mfa->hasDefinition(WebauthnMfaController::class));
        $off = $this->load(['webauthn' => ['mode' => 'enabled', 'mfa' => false], 'mfa' => ['enabled' => true, 'issuer' => 'Acme', 'require_encryption' => false]]);
        self::assertFalse($off->hasDefinition(WebauthnMfaController::class));
    }

    public function testTheMappingIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('webauthn.mode');
        $this->load(['webauthn' => ['mode' => 'enabled'], 'doctrine' => ['register_mapping' => false]]);
    }

    public function testDuplicateRouteNamesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->load(['webauthn' => ['mode' => 'enabled', 'routes' => ['account' => ['name' => 'nowo_auth_kit_login']]]]);
    }

    public function testALockFactoryIsRequired(): void
    {
        $container = $this->load(['webauthn' => ['mode' => 'enabled']]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('profiles.default.webauthn requires symfony/lock');
        (new MfaLockRequiredPass())->process($container);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'rp id with a scheme' => [['rp_id' => 'https://example.com']];
        yield 'rp id with upper case' => [['rp_id' => 'Example.com']];
        yield 'rp id not a string' => [['rp_id' => ['x']]];
        yield 'origin with a path' => [['allowed_origins' => ['https://example.com/login']]];
        yield 'origin without scheme' => [['allowed_origins' => ['example.com']]];
        yield 'origin not a string' => [['allowed_origins' => [['x']]]];
        yield 'plain http origin' => [['allowed_origins' => ['http://example.com']]];
        yield 'empty hash secret' => [['hash_secret' => '']];
        yield 'route path without slash' => [['routes' => ['account' => ['path' => 'account']]]];
        yield 'route path with two slashes' => [['routes' => ['account' => ['path' => '//evil.example']]]];
        yield 'unknown mode' => [['mode' => 'on']];
        yield 'too many passkeys' => [['max_credentials' => 101]];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRefused(array $settings): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['webauthn' => $settings]);
    }

    public function testValidSettingsAreKept(): void
    {
        $webauthn = $this->profile($this->load(['webauthn' => [
            'mode'            => 'enabled',
            'rp_id'           => 'login.example.com',
            'allowed_origins' => ['https://login.example.com', 'http://localhost:8000'],
            'hash_secret'     => 'host-key',
            'attestation'     => 'direct',
        ]]))['webauthn'];
        self::assertSame('login.example.com', $webauthn['rp_id']);
        self::assertSame(['https://login.example.com', 'http://localhost:8000'], $webauthn['allowed_origins']);
        self::assertSame('host-key', $webauthn['hash_secret']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
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
