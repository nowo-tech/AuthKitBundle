<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Doctrine\HostFlushReporter;
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
use Nowo\TransactionDoctrine\HostFlushProbe;
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

/**
 * 1.46.0 `doctrine.private_entity_manager` and `doctrine.mapping_type`: the default keeps the 1.45 wiring; true points
 * every service that reads or writes the AuthKit rows at the private manager (inline definitions included) and leaves
 * the Doctrine service repositories to the application.
 */
final class PrivateEntityManagerConfigTest extends TestCase
{
    private const UNSET_NOTICE = 'Since nowo-tech/auth-kit-bundle 1.54: ' . NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION;

    /** 1.54.0: unset is deprecated (the private manager becomes the only mode in 2.0). */
    #[IgnoreDeprecations]
    public function testTheDefaultKeepsThe145Wiring(): void
    {
        $this->expectUserDeprecationMessage(self::UNSET_NOTICE);
        $container = $this->load([]);

        self::assertFalse($container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertTrue($container->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));
        self::assertFalse($container->hasParameter(NowoAuthKitExtension::XML_MAPPING_ENABLED_PARAMETER));
        foreach ([AuthKitEntityManager::class, AuthKitManagerRegistry::class, PrivateEntityManagerMiddleware::class] as $id) {
            self::assertTrue(!$container->hasDefinition($id) || $container->getDefinition($id)->hasTag('container.excluded'), $id);
        }
        // 1.50.0: the boundaries are tracked in every mode; without the private manager the subscriber only replaces a
        // manager closed by a failed AuthKit write (the runner and the recovery, never the private manager).
        self::assertTrue($container->getDefinition(UnitOfWorkBoundaries::class)->hasTag('kernel.event_subscriber'));
        $resettables = $container->getDefinition(PrivateEntityManagerSubscriber::class)->getArgument(1);
        self::assertInstanceOf(IteratorArgument::class, $resettables);
        self::assertSame([AuthKitTransactionRunner::SERVICE_ID, EntityManagerRecovery::class, HostFlushReporter::class], array_map(static fn (Reference $reference): string => (string) $reference, $resettables->getValues()));
        self::assertSame([], $container->getDefinition(QrLoginChallengeRepository::class)->getArguments());
        $this->assertHostFlushReporterWired($container);
        // 1.50.0: only AuthKit's own runner is bound (never a runner the application binds to the interface).
        self::assertSame([AuthKitTransactionRunner::class], array_keys($container->getDefinition(SocialLoginGate::class)->getBindings()));
        // Never autowired (a host manager named "private" must not be injected).
        self::assertNull($container->getDefinition(SocialAccountLinker::class)->getArgument('$privateEntityManager'));
        self::assertNull($container->getDefinition(InvitationAcceptor::class)->getArgument('$privateEntityManager'));
    }

    #[IgnoreDeprecations]
    public function testXmlMappingReplacesTheAttributeDriver(): void
    {
        $this->expectUserDeprecationMessage(self::UNSET_NOTICE);
        $container = $this->load(['doctrine' => ['mapping_type' => 'xml']]);

        self::assertTrue($container->hasParameter(NowoAuthKitExtension::XML_MAPPING_ENABLED_PARAMETER));
        self::assertFalse($container->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));
    }

    public function testAnUnknownMappingTypeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['doctrine' => ['mapping_type' => 'yaml']]);
    }

    public function testThePrivateManagerIsWiredIntoEveryAuthKitRowService(): void
    {
        $container = $this->load([
            'doctrine' => ['private_entity_manager' => true],
            'mfa'      => ['enabled' => true, 'issuer' => 'Acme', 'require_encryption' => false],
        ]);

        self::assertTrue($container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        $manager = $container->getDefinition(AuthKitEntityManager::class);
        self::assertSame([['method' => 'reset']], $manager->getTag('kernel.reset'));
        self::assertSame([['event' => 'postLoad', 'method' => 'postLoad', 'lazy' => true]], $manager->getTag('doctrine.event_listener'));
        self::assertTrue($container->getDefinition(PrivateEntityManagerSubscriber::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(UnitOfWorkBoundaries::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(PrivateEntityManagerMiddleware::class)->hasTag('doctrine.middleware'));

        $private  = new Reference(AuthKitEntityManager::class);
        $registry = new Reference(AuthKitManagerRegistry::class);
        self::assertEquals($private, $container->getDefinition(QrLoginChallengeRepository::class)->getArgument('$em'));
        self::assertEquals($private, $container->getDefinition(InvitationRepository::class)->getArgument('$em'));
        self::assertEquals($private, $container->getDefinition(SocialAccountLinker::class)->getArgument('$privateEntityManager'));
        self::assertEquals($private, $container->getDefinition(InvitationAcceptor::class)->getArgument('$privateEntityManager'));
        self::assertEquals($registry, $container->getDefinition(MfaStore::class)->getArgument('$doctrine'));

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
        // 1.54.0: no host-flush probe (and no notice: this test fails on any deprecation).
        $this->assertNoHostFlushReporter($container);
        self::assertCount(5, $container->getDefinition(AuthKitTransactionRunner::SERVICE_ID)->getArguments());
        self::assertSame([], $container->getDefinition(InvitationRepository::class)->getMethodCalls());

        $vault = $container->getDefinition(DormantAccountSwitcherPersonalDataEraser::class)->getArgument('$store');
        self::assertInstanceOf(Definition::class, $vault);
        self::assertSame(AccountVaultStore::class, $vault->getClass());
        self::assertEquals($registry, $vault->getArgument('$doctrine'));
        self::assertFalse($container->hasDefinition(DormantMfaPersonalDataEraser::class), 'MFA is on: the real eraser is used');
    }

    public function testThePrivateManagerNeedsTheMapping(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('private_entity_manager: true');
        $this->load(['doctrine' => ['private_entity_manager' => true, 'register_mapping' => false]]);
    }

    #[IgnoreDeprecations]
    public function testFalseIsDeprecatedAndKeepsThe145Wiring(): void
    {
        $this->expectUserDeprecationMessageMatches('/"nowo_auth_kit\.doctrine\.private_entity_manager: false" is deprecated/');
        $container = $this->load(['doctrine' => ['private_entity_manager' => false]]);

        self::assertFalse($container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertTrue($container->getDefinition(AuthKitEntityManager::class)->hasTag('container.excluded'));
        $this->assertHostFlushReporterWired($container);
    }

    /** 1.54.0: without the mapping true is refused, so neither the notice nor the probe applies. */
    public function testUnsetWithoutTheMappingHasNoNoticeNorProbe(): void
    {
        $container = $this->load(['doctrine' => ['register_mapping' => false]]);

        $this->assertNoHostFlushReporter($container);
        self::assertCount(5, $container->getDefinition(AuthKitTransactionRunner::SERVICE_ID)->getArguments());
    }

    /** 1.54.0: the MFA store builds its own runner and gets the reporter by autowiring (nullable argument). */
    #[IgnoreDeprecations]
    public function testTheMfaStoreCanAutowireTheReporterInHostMode(): void
    {
        $this->expectUserDeprecationMessage(self::UNSET_NOTICE);
        $container = $this->load(['mfa' => ['enabled' => true, 'issuer' => 'Acme', 'require_encryption' => false]]);

        self::assertTrue($container->getDefinition(MfaStore::class)->isAutowired());
        self::assertTrue($container->hasDefinition(HostFlushReporter::class));
    }

    private function assertNoHostFlushReporter(ContainerBuilder $container): void
    {
        // The services resource registers it as an excluded (abstract) definition only.
        self::assertTrue(!$container->hasDefinition(HostFlushReporter::class) || $container->getDefinition(HostFlushReporter::class)->hasTag('container.excluded'));
    }

    private function assertHostFlushReporterWired(ContainerBuilder $container): void
    {
        $reporter = $container->getDefinition(HostFlushReporter::class);
        self::assertSame(HostFlushReporter::class, $reporter->getClass());
        self::assertEquals([new Reference(HostFlushProbe::SERVICE_ID, ContainerBuilder::NULL_ON_INVALID_REFERENCE)], $reporter->getArguments());
        self::assertSame([['method' => 'reset']], $reporter->getTag('kernel.reset'));
        self::assertEquals(new Reference(HostFlushReporter::class), $container->getDefinition(AuthKitTransactionRunner::SERVICE_ID)->getArgument(5));
        foreach ([QrLoginChallengeRepository::class, InvitationRepository::class] as $repository) {
            self::assertEquals([['setHostFlushReporter', [new Reference(HostFlushReporter::class)]]], $container->getDefinition($repository)->getMethodCalls(), $repository);
        }
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
