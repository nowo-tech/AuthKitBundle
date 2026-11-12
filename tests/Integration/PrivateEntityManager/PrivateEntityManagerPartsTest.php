<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use DateTimeImmutable;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\AuthKitSavepoint;
use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerConnection;
use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\RecordingLogger;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function assert;

/**
 * 1.46.0 private entity manager parts (adapted from VaultBundle 1.19.0, audits NJ-2, NJV-1, NF-2, NF2-2, NF3-2, NF-7):
 * the rollback middleware, the replacement rules, the boundary drop that cannot be poisoned, the registry the AuthKit
 * services see, the repositories bound to the decorator, the savepoint helper and the build pass.
 */
final class PrivateEntityManagerPartsTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return PrivateEmMfaTestKernel::class;
    }

    #[Test]
    public function onlyARollbackOfRowsThePrivateManagerWroteMakesItStale(): void
    {
        $private = $this->privateManager();
        $real    = $private->getConnection();
        $inner   = $this->createMock(DriverConnection::class);
        $inner->method('exec')->willReturn(0);
        $inner->method('commit')->willReturnOnConsecutiveCalls(null, self::throwException(new RuntimeException('commit failed')));
        $connection = new PrivateEntityManagerConnection($inner, $private);
        $real->beginTransaction();

        $this->write($private);
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        $connection->exec('ROLLBACK TO SAVEPOINT nowo_auth_kit_0a1b2c');
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertFalse($private->isStale(), 'NJ-2: an AuthKit savepoint or a deeper level the manager did not write in');
        $connection->exec('ROLLBACK TO SAVEPOINT host_point');
        self::assertTrue($private->isStale(), 'a savepoint of unknown level: conservative');

        $private->reset();
        $this->write($private);
        $connection->rollBack();
        self::assertTrue($private->isStale());

        $private->reset();
        $this->write($private);
        $connection->commit();
        $connection->rollBack();
        self::assertFalse($private->isStale(), 'committed rows are not undone by a later rollback');

        $private->reset();
        $this->write($private);
        try {
            $connection->commit();
            self::fail('the commit failure is rethrown');
        } catch (RuntimeException) {
        }
        self::assertTrue($private->isStale());

        // Rows written in a nested level move down when its savepoint is released.
        $private->reset();
        $real->beginTransaction();
        $this->write($private);
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertFalse($private->isStale());
        $connection->rollBack();
        self::assertTrue($private->isStale());
        $real->rollBack();
        $real->rollBack();

        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($this->createMock(DriverConnection::class));
        self::assertInstanceOf(PrivateEntityManagerConnection::class, (new PrivateEntityManagerMiddleware($private))->wrap($driver)->connect([]));
    }

    #[Test]
    public function aStaleManagerWithPendingWorkIsKeptUntilFlushed(): void
    {
        $private = $this->privateManager();
        $real    = $private->getConnection();
        $real->beginTransaction();
        $this->write($private);
        $live = $private->live();
        $private->persist($this->invitation('pending@example.com'));
        $private->connectionRolledBack(1);

        self::assertSame($live, $private->live(), 'pending inserts survive a rollback signal');
        $private->flush();
        $private->connectionRolledBack(1);
        self::assertNotSame($live, $private->live(), 'no pending work: replaced');
        $private->connectionRolledBack(null);
        self::assertFalse($private->isStale(), 'a fresh manager wrote nothing');
        $private->wrapInTransaction(static fn (): bool => true);
        $real->rollBack();
        $private->clear();
        (new AuthKitEntityManager($this->doctrine()))->clear();
    }

    #[Test]
    public function aClosedManagerIsReplacedAndLoadsAndRefreshesAreTracked(): void
    {
        $this->doctrine();
        $private = self::getContainer()->get(AuthKitEntityManager::class);
        self::assertInstanceOf(AuthKitEntityManager::class, $private);
        $live = $private->live();
        $live->close();
        self::assertNotSame($live, $private->live(), 'a closed manager is created again');

        $invitation = $this->invitation('loaded@example.com');
        $private->persist($invitation);
        $private->flush();
        $private->clear();
        $real = $private->getConnection();
        $real->beginTransaction();
        $loaded = $private->find(Invitation::class, $invitation->getId());
        self::assertInstanceOf(Invitation::class, $loaded);
        $private->refresh($loaded);
        $private->getConnection()->rollBack();
        self::assertTrue($private->isStale(), 'NJV-1: a load inside a rolled-back transaction makes the identity map stale');
    }

    private function write(AuthKitEntityManager $private): void
    {
        $private->persist($this->invitation(bin2hex(random_bytes(4)) . '@example.com'));
        $private->flush();
    }

    private function invitation(string $email): Invitation
    {
        $invitation = new Invitation('default', $email, null, 7, new DateTimeImmutable());
        $invitation->issue(bin2hex(random_bytes(16)), 7, new DateTimeImmutable());

        return $invitation;
    }

    #[Test]
    public function aBoundaryThatCannotInspectOrClearStillDropsTheManager(): void
    {
        $logger  = new RecordingLogger();
        $private = new AuthKitEntityManager($this->doctrine(), 'default', $logger);
        $failing = $this->createMock(EntityManagerInterface::class);
        $failing->method('isOpen')->willReturn(true);
        $failing->method('getUnitOfWork')->willThrowException(new RuntimeException('change sets'));
        $failing->method('clear')->willThrowException(new RuntimeException('onClear listener'));
        (new ReflectionProperty(AuthKitEntityManager::class, 'current'))->setValue($private, $failing);

        $private->reset();

        self::assertFalse($private->isCreated());
        self::assertCount(2, $logger->records, 'inspection and clear failures are logged, never rethrown');

        $quiet = new AuthKitEntityManager($this->doctrine(), 'default', new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        });
        (new ReflectionProperty(AuthKitEntityManager::class, 'current'))->setValue($quiet, $failing);
        $quiet->reset();
        self::assertFalse($quiet->isCreated());

        $closed = new AuthKitEntityManager($this->doctrine());
        $closed->live()->close();
        $closed->reset();
        self::assertFalse($closed->isCreated());
    }

    #[Test]
    public function aBoundaryLogsTheDroppedChangesByClassOnly(): void
    {
        $logger  = new RecordingLogger();
        $private = new AuthKitEntityManager($this->doctrine(), 'default', $logger);
        $private->persist($this->invitation('dropped@example.com'));
        $private->reset();

        self::assertCount(1, $logger->records);
        self::assertSame([Invitation::class => 1], $logger->records[0][1]['changes']);
        self::assertStringNotContainsString('dropped@example.com', (string) json_encode($logger->records));

        $silent = new AuthKitEntityManager($this->doctrine());
        $silent->persist($this->invitation('silent@example.com'));
        $silent->reset();
        self::assertFalse($silent->isCreated(), 'without a logger the changes are dropped silently');
        $nothing = new AuthKitEntityManager($this->doctrine(), 'default', $logger);
        $nothing->live();
        $nothing->reset();
        self::assertCount(1, $logger->records, 'nothing pending: nothing logged');
    }

    #[Test]
    public function theSourceMustBeAnOrmManagerThatMapsTheAuthKitEntities(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not map');
        (new AuthKitEntityManager($doctrine, 'other'))->source();
    }

    #[Test]
    public function theSourceSqlFiltersAreMirroredWithTheirParameters(): void
    {
        $doctrine = $this->doctrine();
        $source   = $doctrine->getManager();
        assert($source instanceof EntityManagerInterface);
        $private = new AuthKitEntityManager($doctrine);
        $first   = $this->invitation('one@example.com');
        $second  = new Invitation('other', 'two@example.com', null, 7, new DateTimeImmutable());
        $second->issue(bin2hex(random_bytes(16)), 7, new DateTimeImmutable());
        $private->persist($first);
        $private->persist($second);
        $private->flush();
        $source->getConfiguration()->addFilter('test_invitation_profile', InvitationProfileFilter::class);

        $source->getFilters()->enable('test_invitation_profile')->setParameter('profile', 'default');
        self::assertSame(['one@example.com'], $this->emails($private));
        $copy = $private->live()->getFilters()->getFilter('test_invitation_profile');
        self::assertSame($copy, $private->live()->getFilters()->getFilter('test_invitation_profile'), 'an unchanged filter is kept');
        $source->getFilters()->getFilter('test_invitation_profile')->setParameter('profile', 'other');
        self::assertSame(['two@example.com'], $this->emails($private), 'a changed parameter is copied');
        $source->getFilters()->getFilter('test_invitation_profile')->setParameterList('profiles', ['default', 'other'], 'string');
        self::assertSame(['two@example.com'], $this->emails($private), 'a list parameter keeps its type');
        $source->getFilters()->disable('test_invitation_profile');
        self::assertSame(['one@example.com', 'two@example.com'], $this->emails($private), 'a filter the source disabled is disabled');
    }

    /**
     * @return list<string>
     */
    private function emails(AuthKitEntityManager $private): array
    {
        $private->clear();
        $emails = [];
        /** @var list<Invitation> $invitations */
        $invitations = $private->createQueryBuilder()->select('i')->from(Invitation::class, 'i')->orderBy('i.email')->getQuery()->getResult();
        foreach ($invitations as $invitation) {
            $emails[] = $invitation->getEmail();
        }

        return $emails;
    }

    #[Test]
    public function repositoriesWithoutACustomClassAreBoundToTheDecoratorOnce(): void
    {
        $private    = $this->privateManager();
        $repository = $private->getRepository(MfaCredential::class);
        self::assertSame($repository, $private->getRepository(MfaCredential::class));
        $private->connectionRolledBack(null);
        self::assertSame($repository, $private->getRepository(MfaCredential::class), 'kept while the manager lives: the factory is not asked again');
        $private->reset();
        self::assertNotSame($repository, $private->getRepository(MfaCredential::class), 'emptied with the manager at a boundary');
        self::assertSame($private, $repository->createQueryBuilder('c')->getEntityManager() === $private->live() ? $private : null);
        self::assertInstanceOf(EntityRepository::class, $private->getRepository(SocialLoginAccount::class), 'a custom repository class comes from the factory');
    }

    #[Test]
    public function undefinedMagicPropertiesFail(): void
    {
        $this->expectException(LogicException::class);
        $this->privateManager()->__get('other');
    }

    #[Test]
    public function theRegistryServesThePrivateManagerAndDelegatesTheRest(): void
    {
        $doctrine = $this->doctrine();
        $private  = new AuthKitEntityManager($doctrine);
        $registry = new AuthKitManagerRegistry($doctrine, $private, 'default');

        self::assertSame($private, $registry->getManager());
        self::assertSame($private, $registry->getManager('default'));
        self::assertSame($doctrine->getManagers(), $registry->getManagers());
        self::assertSame($doctrine->getManagerNames(), $registry->getManagerNames());
        self::assertSame($doctrine->getDefaultManagerName(), $registry->getDefaultManagerName());
        self::assertSame($doctrine->getDefaultConnectionName(), $registry->getDefaultConnectionName());
        self::assertSame($doctrine->getConnection(), $registry->getConnection());
        self::assertSame($doctrine->getConnections(), $registry->getConnections());
        self::assertSame($doctrine->getConnectionNames(), $registry->getConnectionNames());
        self::assertSame($doctrine->getManagerForClass(KernelUser::class), $registry->getManagerForClass(KernelUser::class));
        self::assertSame($private, $registry->getManagerForClass($this->proxyClass()));
        self::assertSame($private->getRepository(MfaCredential::class), $registry->getRepository(MfaCredential::class));
        self::assertSame($doctrine->getRepository(KernelUser::class), $registry->getRepository(KernelUser::class));
        $private->live();
        self::assertSame($private, $registry->resetManager());
        self::assertFalse($private->isCreated(), 'resetting the source name drops the private manager');

        $other = new AuthKitManagerRegistry($doctrine, $private, 'auth');
        self::assertSame($doctrine->getManager(), $other->getManager(), 'another name: the application manager');
        self::assertSame($doctrine->getManager('default'), $other->resetManager('default'));
    }

    #[Test]
    public function anAuthKitWriteInsideATransactionRunsInASavepointWithThePrivateManagerOnly(): void
    {
        $private    = $this->privateManager();
        $host       = $private->source();
        $connection = $private->getConnection();
        self::assertSame(1, AuthKitSavepoint::write($connection, static fn (): int => 1, $private), 'outside a transaction: as is');

        $connection->beginTransaction();
        try {
            AuthKitSavepoint::write($connection, static function (): never {
                throw new RuntimeException('failing statement');
            }, $private);
        } catch (RuntimeException) {
        }
        self::assertTrue($connection->isTransactionActive(), 'the host transaction is untouched');
        self::assertSame(2, AuthKitSavepoint::write($connection, static fn (): int => 2, $private));
        self::assertSame(1, $connection->getTransactionNestingLevel());

        // The application's manager (default mode): exactly the statement, no savepoint.
        $levels = [];
        AuthKitSavepoint::write($connection, static function () use ($connection, &$levels): int {
            $levels[] = $connection->getTransactionNestingLevel();

            return 3;
        }, $host);
        self::assertSame([1], $levels);
        $connection->rollBack();
    }

    #[Test]
    public function theBuildPassWiresTheDefaultManagerAndWrapsOnlyItsConnection(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $container->setDefinition(AuthKitEntityManager::class, new Definition(AuthKitEntityManager::class));
        $container->setDefinition(AuthKitManagerRegistry::class, new Definition(AuthKitManagerRegistry::class));
        $container->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition(EntityManagerInterface::class, [new Reference('doctrine.dbal.auth_connection')]));
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame('main', $container->getDefinition(AuthKitEntityManager::class)->getArgument('$sourceManagerName'));
        self::assertSame('main', $container->getDefinition(AuthKitManagerRegistry::class)->getArgument('$managerName'));
        self::assertSame([['connection' => 'auth']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $container->removeDefinition('doctrine.orm.main_entity_manager');
        $container->setParameter('doctrine.default_connection', 'primary');
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'primary']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $bare = new ContainerBuilder();
        $bare->setParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $bare->setDefinition(AuthKitEntityManager::class, new Definition(AuthKitEntityManager::class));
        $bare->setDefinition(AuthKitManagerRegistry::class, new Definition(AuthKitManagerRegistry::class));
        $bare->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));
        (new PrivateEntityManagerPass())->process($bare);
        self::assertSame('default', $bare->getDefinition(AuthKitEntityManager::class)->getArgument('$sourceManagerName'));
        self::assertSame([['connection' => 'default']], $bare->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        (new PrivateEntityManagerPass())->process(new ContainerBuilder());
        $off = new ContainerBuilder();
        $off->setParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, false);
        $off->setDefinition(AuthKitEntityManager::class, new Definition(AuthKitEntityManager::class));
        (new PrivateEntityManagerPass())->process($off);
        self::assertSame([], $off->getDefinition(AuthKitEntityManager::class)->getArguments(), 'option off: nothing wired');
        $excluded = new ContainerBuilder();
        $excluded->setParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $excluded->setDefinition(AuthKitEntityManager::class, new Definition(AuthKitEntityManager::class));
        $excluded->setDefinition(AuthKitManagerRegistry::class, new Definition(AuthKitManagerRegistry::class));
        $excluded->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('container.excluded'));
        (new PrivateEntityManagerPass())->process($excluded);
        self::assertFalse($excluded->getDefinition(PrivateEntityManagerMiddleware::class)->hasTag('doctrine.middleware'), 'DBAL 3: the excluded class is not attached');
        $withoutMiddleware = new ContainerBuilder();
        $withoutMiddleware->setParameter(NowoAuthKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $withoutMiddleware->setDefinition(AuthKitEntityManager::class, new Definition(AuthKitEntityManager::class));
        $withoutMiddleware->setDefinition(AuthKitManagerRegistry::class, new Definition(AuthKitManagerRegistry::class));
        (new PrivateEntityManagerPass())->process($withoutMiddleware);
        self::assertFalse($withoutMiddleware->hasDefinition(PrivateEntityManagerMiddleware::class), 'DBAL 3: no middleware to attach');
        self::assertTrue(PrivateEntityManagerPass::supportsRollbackMiddleware());
    }

    private function doctrine(): ManagerRegistry
    {
        self::bootKernel();
        $doctrine = self::getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $em = $doctrine->getManager();
        assert($em instanceof EntityManagerInterface);
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        return $doctrine;
    }

    private function privateManager(): AuthKitEntityManager
    {
        $private = new AuthKitEntityManager($this->doctrine());
        $private->live();

        return $private;
    }

    /**
     * @return class-string a Doctrine proxy class name of an AuthKit entity
     */
    private function proxyClass(): string
    {
        return 'Proxies\\__CG__\\' . Invitation::class; // @phpstan-ignore return.type (no such class is loaded: the registry only reads the name)
    }
}
