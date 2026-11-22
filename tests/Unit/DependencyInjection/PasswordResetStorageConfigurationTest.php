<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\PasswordResetTableInvalidationListener;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManager;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\ProfilePasswordResetTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * `password_reset.storage` (1.46.0): `user_entity` by default (wiring unchanged), `table` per profile switches the
 * store to the per-profile manager, tags the table invalidation listener and needs the AuthKit mapping.
 */
final class PasswordResetStorageConfigurationTest extends TestCase
{
    public function testDefaultKeepsTheUserEntityStore(): void
    {
        $container = $this->load([]);

        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = $container->getParameter('nowo_auth_kit.profiles');
        self::assertSame('user_entity', $profiles['default']['password_reset']['storage']);
        self::assertSame(PasswordResetTokenManager::class, (string) $container->getAlias(PasswordResetTokenManagerInterface::class));
        self::assertFalse($container->getDefinition(PasswordResetTableInvalidationListener::class)->hasTag('doctrine.event_listener'));
    }

    public function testTableSwitchesTheStoreAndMapsTheEntities(): void
    {
        $container = $this->load(['doctrine' => ['register_mapping' => 'auto'], 'password_reset' => ['storage' => 'table']]);

        self::assertSame(ProfilePasswordResetTokenManager::class, (string) $container->getAlias(PasswordResetTokenManagerInterface::class));
        self::assertSame([['event' => 'onFlush']], $container->getDefinition(PasswordResetTableInvalidationListener::class)->getTag('doctrine.event_listener'));
        self::assertTrue($container->getParameter('nowo_auth_kit.doctrine.register_mapping'));
    }

    public function testTableNeedsTheMapping(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('password_reset.storage "user_entity"');

        $this->load(['doctrine' => ['register_mapping' => false], 'password_reset' => ['storage' => 'table']]);
    }

    public function testUnknownStorageIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['password_reset' => ['storage' => 'session']]);
    }

    public function testPrependMapsTheEntitiesForTheTableUnderAuto(): void
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
        $container->prependExtensionConfig('doctrine', ['orm' => ['auto_mapping' => true]]);
        $container->prependExtensionConfig('nowo_auth_kit', ['doctrine' => ['register_mapping' => 'auto'], 'profiles' => ['other' => null, 'admin' => ['password_reset' => null], 'staff' => ['password_reset' => ['storage' => 'table']]]]);

        (new NowoAuthKitExtension())->prepend($container);

        self::assertSame([['orm' => ['auto_mapping' => true]]], $container->getExtensionConfig('doctrine'), 'The AuthKit mapping is not opted out.');
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
}
