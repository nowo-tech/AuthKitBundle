<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\MultiAccountPass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuer;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_column;
use function array_filter;
use function array_keys;
use function json_encode;
use function str_contains;

/**
 * `profiles.*.multi_account` (1.34): off by default (no service), owner decisions as defaults, per-firewall listeners,
 * admin-link recording, route names, mapping.
 */
final class MultiAccountConfigurationTest extends TestCase
{
    public function testDefaultsRegisterNothing(): void
    {
        $container = $this->load([]);
        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = $container->getParameter('nowo_auth_kit.profiles');
        $config   = $profiles['default']['multi_account'];
        self::assertFalse($config['enabled']);
        self::assertSame(5, $config['max_accounts']);
        self::assertSame('all', $config['logout_scope']);
        self::assertNull($config['cookie']['lifetime']);
        self::assertSame('masked', $config['chooser']['identifier_display']);
        self::assertFalse($config['chooser']['cross_tab_notice']);
        self::assertTrue($config['revive_with_remember_me']);
        self::assertSame(2592000, $config['max_lifetime']);
        self::assertNull($config['hash_secret']);
        self::assertSame([], array_filter(array_keys($container->getDefinitions()), static fn (string $id): bool => str_contains($id, '\\MultiAccount\\')));
        self::assertArrayNotHasKey('$issuedLinks', $container->getDefinition(AdminMagicLinkIssuer::class)->getArguments(), 'Admin links are recorded only with the feature on.');
    }

    public function testEnabledRegistersTheServices(): void
    {
        $container = $this->load(['multi_account' => ['enabled' => true, 'enrolment_gate' => 'app.gate', 'presenter' => 'app.presenter']]);
        self::assertTrue($container->hasDefinition(AccountVault::class));
        self::assertSame(['main'], $container->getParameter(MultiAccountPass::FIREWALLS_PARAMETER));
        $tags = $container->getDefinition(MultiAccountSubscriber::class)->getTag('kernel.event_listener');
        self::assertContains('security.event_dispatcher.main', array_column($tags, 'dispatcher'));
        self::assertInstanceOf(Reference::class, $container->getDefinition(AdminMagicLinkIssuer::class)->getArgument('$issuedLinks'));
        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = $container->getParameter('nowo_auth_kit.profiles');
        self::assertSame('%kernel.secret%', $profiles['default']['multi_account']['hash_secret']);
    }

    public function testRouteNamesMustBeUnique(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate route name "nowo_auth_kit_login"');
        $this->load(['multi_account' => ['enabled' => true, 'routes' => ['chooser' => ['name' => 'nowo_auth_kit_login']]]]);
    }

    public function testMappingFalseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->load(['doctrine' => ['register_mapping' => false], 'multi_account' => ['enabled' => true]]);
    }

    public function testInvalidValues(): void
    {
        foreach ([['max_accounts' => 1], ['cookie' => ['name' => 'a b']], ['routes' => ['add' => ['path' => 'x']]], ['logout_scope' => 'some']] as $bad) {
            try {
                $this->load(['multi_account' => $bad]);
                self::fail('Refused: ' . json_encode($bad));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
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
