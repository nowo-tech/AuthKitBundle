<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitManagerRegistry;
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
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.46.0 `doctrine.private_entity_manager` and `doctrine.mapping_type`: the default keeps the 1.45 wiring; true points
 * every service that reads or writes the AuthKit rows at the private manager (inline definitions included) and leaves
 * the Doctrine service repositories to the application.
 */
final class PrivateEntityManagerConfigTest extends TestCase
{
    public function testTheDefaultKeepsThe145Wiring(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->getParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertTrue($container->hasParameter(NowoAuthKitExtension::MAPPING_ENABLED_PARAMETER));
        self::assertFalse($container->hasParameter(NowoAuthKitExtension::XML_MAPPING_ENABLED_PARAMETER));
        foreach ([AuthKitEntityManager::class, AuthKitManagerRegistry::class, UnitOfWorkBoundaries::class, PrivateEntityManagerSubscriber::class, PrivateEntityManagerMiddleware::class] as $id) {
            self::assertTrue(!$container->hasDefinition($id) || $container->getDefinition($id)->hasTag('container.excluded'), $id);
        }
        self::assertSame([], $container->getDefinition(QrLoginChallengeRepository::class)->getArguments());
        self::assertSame([], $container->getDefinition(SocialLoginGate::class)->getBindings());
        // Never autowired (a host manager named "private" must not be injected).
        self::assertNull($container->getDefinition(SocialAccountLinker::class)->getArgument('$privateEntityManager'));
        self::assertNull($container->getDefinition(InvitationAcceptor::class)->getArgument('$privateEntityManager'));
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
