<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerSubscriber;
use Nowo\AuthKitBundle\Doctrine\UnitOfWorkBoundaries;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\PersonalData\DormantAccountSwitcherPersonalDataEraser;
use Nowo\AuthKitBundle\PersonalData\DormantMfaPersonalDataEraser;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Repository\QrLoginChallengeRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function array_map;
use function class_exists;

/**
 * `doctrine.private_entity_manager` and `doctrine.mapping_type`. 2.0: with the AuthKit entities mapped, every service
 * that reads or writes the AuthKit rows uses the private manager (inline definitions included) and the Doctrine service
 * repositories stay with the application; `false` fails the build, `true` is a deprecated no-op.
 */
final class PrivateEntityManagerConfigTest extends TestCase
{
    private const TRUE_NOTICE = 'Since nowo-tech/auth-kit-bundle 2.0: The "nowo_auth_kit.doctrine.private_entity_manager" option has no effect since 2.0 (the private entity manager is the only mode); remove it.';

    /** 2.0: unset (the default) is the private manager, with no notice (this test fails on any deprecation). */
    public function testTheDefaultIsThePrivateManager(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertTrue($container->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));
        self::assertFalse($container->hasParameter(NowoAuthKitExtension::XML_MAPPING_ENABLED_PARAMETER));
        self::assertFalse($container->getDefinition(AuthKitEntityManager::class)->hasTag('container.excluded'));
        $this->assertPrivateWiring($container);
        // 1.50.0: only AuthKit's own runner is bound (never a runner the application binds to the interface).
        self::assertSame([AuthKitTransactionRunner::class, SocialLoginAccountRepository::class, SocialLoginCredentialRepository::class], array_keys($container->getDefinition(SocialLoginGate::class)->getBindings()));
    }

    public function testXmlMappingReplacesTheAttributeDriver(): void
    {
        $container = $this->load(['doctrine' => ['mapping_type' => 'xml']]);

        self::assertTrue($container->hasParameter(NowoAuthKitExtension::XML_MAPPING_ENABLED_PARAMETER));
        self::assertFalse($container->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));
    }

    public function testAnUnknownMappingTypeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['doctrine' => ['mapping_type' => 'yaml']]);
    }

    #[IgnoreDeprecations]
    public function testTrueIsADeprecatedNoOp(): void
    {
        $this->expectUserDeprecationMessage(self::TRUE_NOTICE);
        $container = $this->load([
            'doctrine' => ['private_entity_manager' => true],
            'mfa'      => ['enabled' => true, 'issuer' => 'Acme', 'require_encryption' => false],
        ]);

        self::assertTrue($container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        $this->assertPrivateWiring($container);
        self::assertFalse($container->hasDefinition(DormantMfaPersonalDataEraser::class), 'MFA is on: the real eraser is used');
    }

    /** As in 1.x: without the AuthKit entities mapped there is no private manager to opt into. */
    #[IgnoreDeprecations]
    public function testTrueWithoutTheMappingIsStillRefused(): void
    {
        $this->expectUserDeprecationMessage(self::TRUE_NOTICE);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('private_entity_manager: true');
        $this->load(['doctrine' => ['private_entity_manager' => true, 'register_mapping' => false]]);
    }

    /** 2.0: false would bring back the removed host mode: the build fails and names UPGRADING. */
    #[IgnoreDeprecations]
    public function testFalseFailsTheBuild(): void
    {
        $this->expectUserDeprecationMessage(self::TRUE_NOTICE);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_ERROR);
        $this->load(['doctrine' => ['private_entity_manager' => false]]);
    }

    /** Without the AuthKit entities mapped there are no AuthKit rows: no private manager, no notice, nothing changes. */
    public function testWithoutTheMappingThereIsNoPrivateManager(): void
    {
        $container = $this->load(['doctrine' => ['register_mapping' => false]]);

        self::assertFalse($container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        foreach ([AuthKitEntityManager::class, AuthKitManagerRegistry::class, PrivateEntityManagerMiddleware::class] as $id) {
            self::assertTrue(!$container->hasDefinition($id) || $container->getDefinition($id)->hasTag('container.excluded'), $id);
        }
        // 1.50.0: the boundaries are tracked in every mode; the subscriber only replaces a manager closed by a failed
        // AuthKit write (the runner and the recovery).
        $resettables = $container->getDefinition(PrivateEntityManagerSubscriber::class)->getArgument(1);
        self::assertInstanceOf(IteratorArgument::class, $resettables);
        self::assertSame([AuthKitTransactionRunner::SERVICE_ID, EntityManagerRecovery::class], array_map(static fn (Reference $reference): string => (string) $reference, $resettables->getValues()));
        self::assertCount(5, $container->getDefinition(AuthKitTransactionRunner::SERVICE_ID)->getArguments());
        self::assertSame([], $container->getDefinition(QrLoginChallengeRepository::class)->getMethodCalls());
    }

    /** 2.0: the host-flush reporter of the removed host mode is gone, and the runner gets no sixth argument. */
    public function testNoHostFlushReporterIsRegistered(): void
    {
        $container = $this->load(['mfa' => ['enabled' => true, 'issuer' => 'Acme', 'require_encryption' => false]]);

        self::assertFalse($container->hasDefinition('Nowo\\AuthKitBundle\\Doctrine\\HostFlushReporter'));
        self::assertFalse(class_exists('Nowo\\AuthKitBundle\\Doctrine\\HostFlushReporter'));
        self::assertCount(5, $container->getDefinition(AuthKitTransactionRunner::SERVICE_ID)->getArguments());
        foreach ([QrLoginChallengeRepository::class, InvitationRepository::class] as $repository) {
            self::assertSame([], $container->getDefinition($repository)->getMethodCalls(), $repository);
        }
    }

    private function assertPrivateWiring(ContainerBuilder $container): void
    {
        $manager = $container->getDefinition(AuthKitEntityManager::class);
        self::assertSame([['method' => 'reset']], $manager->getTag('kernel.reset'));
        self::assertSame([['event' => 'postLoad', 'method' => 'postLoad', 'lazy' => true]], $manager->getTag('doctrine.event_listener'));
        self::assertTrue($container->getDefinition(PrivateEntityManagerSubscriber::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(UnitOfWorkBoundaries::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(PrivateEntityManagerMiddleware::class)->hasTag('doctrine.middleware'));
        $resettables = $container->getDefinition(PrivateEntityManagerSubscriber::class)->getArgument(1);
        self::assertInstanceOf(IteratorArgument::class, $resettables);
        self::assertSame([AuthKitTransactionRunner::SERVICE_ID, EntityManagerRecovery::class, AuthKitEntityManager::class], array_map(static fn (Reference $reference): string => (string) $reference, $resettables->getValues()));

        $private  = new Reference(AuthKitEntityManager::class);
        $registry = new Reference(AuthKitManagerRegistry::class);
        self::assertEquals($private, $container->getDefinition(QrLoginChallengeRepository::class)->getArgument('$em'));
        self::assertEquals($private, $container->getDefinition(InvitationRepository::class)->getArgument('$em'));
        self::assertEquals($private, $container->getDefinition(SocialAccountLinker::class)->getArgument('$privateEntityManager'));
        self::assertEquals($private, $container->getDefinition(InvitationAcceptor::class)->getArgument('$privateEntityManager'));
        self::assertEquals($private, $container->getDefinition(AuthKitTransactionRunner::SERVICE_ID)->getArgument(0));
        if ($container->hasDefinition(MfaStore::class)) {
            self::assertEquals($registry, $container->getDefinition(MfaStore::class)->getArgument('$doctrine'));
        }

        // The Doctrine service repositories stay on the application's manager; the bundle services get private copies.
        self::assertSame([], $container->getDefinition(SocialLoginAccountRepository::class)->getArguments());
        foreach ([SocialLoginAccountRepository::class, SocialLoginCredentialRepository::class] as $class) {
            $copy = $container->getDefinition(NowoAuthKitExtension::PRIVATE_REPOSITORY_PREFIX . $class);
            self::assertSame($class, $copy->getClass());
            self::assertEquals($registry, $copy->getArgument('$registry'));
            self::assertSame([], $copy->getTags(), 'not a Doctrine service repository');
            $binding = $container->getDefinition(SocialLoginGate::class)->getBindings()[$class] ?? null;
            self::assertInstanceOf(BoundArgument::class, $binding);
            self::assertEquals(new Reference(NowoAuthKitExtension::PRIVATE_REPOSITORY_PREFIX . $class), $binding->getValues()[0]);
            self::assertArrayNotHasKey($class, $container->getDefinition($class)->getBindings(), 'the application\'s repository keeps its wiring');
        }

        // Inline definitions (the dormant erasers build their stores inline).
        $vault = $container->getDefinition(DormantAccountSwitcherPersonalDataEraser::class)->getArgument('$store');
        self::assertInstanceOf(Definition::class, $vault);
        self::assertSame(AccountVaultStore::class, $vault->getClass());
        self::assertEquals($registry, $vault->getArgument('$doctrine'));
        self::assertCount(5, $container->getDefinition(AuthKitTransactionRunner::SERVICE_ID)->getArguments());
        // Never autowired (a host manager named "private" must not be injected): set explicitly.
        self::assertSame([], $container->getDefinition(InvitationRepository::class)->getMethodCalls());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        (new NowoAuthKitExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }
}
