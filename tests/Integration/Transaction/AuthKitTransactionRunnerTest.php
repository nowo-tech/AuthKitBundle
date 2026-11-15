<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Transaction;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity\LoadAccount;
use Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity\LoadMembership;
use Nowo\AuthKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Nowo\TransactionContract\TransactionRunnerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function dirname;

/**
 * 1.50.0 (Beacon spec 239): AuthKit's transaction runner on a real (SQLite) entity manager. One transaction per unit,
 * the pending work flushed first on its own, and after a failure: no level left open, the manager never reset nor
 * cleared, only the rows the unit touched detached; a manager the ORM closed stays closed until the boundary.
 */
final class AuthKitTransactionRunnerTest extends TestCase
{
    private SqliteManagerRegistry $registry;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->registry = new SqliteManagerRegistry([
            'default' => [
                'Nowo\\AuthKitBundle\\Entity'                                  => dirname(__DIR__, 3) . '/src/Entity',
                'Nowo\\AuthKitBundle\\Tests\\Integration\\Transaction\\Entity' => __DIR__ . '/Entity',
            ],
            'other' => ['Nowo\\AuthKitBundle\\Tests\\Integration\\Transaction\\Entity' => __DIR__ . '/Entity'],
        ]);
        $this->em = $this->manager('default');
        $this->schema($this->em);
    }

    public function testAUnitCommitsItsChangesInOneTransaction(): void
    {
        $runner = new AuthKitTransactionRunner($this->em);
        self::assertSame($this->em->getConnection(), $runner->connection());

        $result = $runner->run(function (): string {
            self::assertSame(1, $this->em->getConnection()->getTransactionNestingLevel());
            $this->em->persist(new LoadAccount('a', 'Ada'));

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertSame('Ada', $this->em->getConnection()->fetchOne("SELECT name FROM load_account WHERE id = 'a'"));
    }

    public function testPendingWorkIsFlushedFirstSoAFailedUnitNeverRollsItBack(): void
    {
        $pending = new LoadAccount('pending', 'host');
        $this->em->persist($pending);

        $this->failing(new AuthKitTransactionRunner($this->em), static function (): never {
            throw new RuntimeException('refused');
        });

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM load_account WHERE id = 'pending'"));
        self::assertTrue($this->em->contains($pending), 'the application\'s entity stays managed');
        self::assertTrue($this->em->isOpen());
    }

    public function testAFailedUnitDetachesOnlyTheRowsItTouched(): void
    {
        $kept    = new LoadAccount('kept', 'Kept');
        $changed = new LoadAccount('changed', 'Before');
        $this->em->persist($kept);
        $this->em->persist($changed);
        $this->em->flush();
        $reference = $this->em->getReference(LoadAccount::class, 'elsewhere');
        self::assertNotNull($reference);
        $member  = new LoadMembership($kept);
        $pointer = new LoadMembership($changed);
        $this->em->persist($member);
        $this->em->persist($pointer);
        $this->em->flush();
        $added = new LoadAccount('added', 'New');

        $this->failing(new AuthKitTransactionRunner($this->em), function () use ($changed, $added): never {
            $changed->name = 'After';
            $this->em->persist($added);
            $this->em->flush();

            throw new RuntimeException('refused after the flush');
        });

        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertSame('Before', $this->em->getConnection()->fetchOne("SELECT name FROM load_account WHERE id = 'changed'"), 'rolled back');
        self::assertFalse($this->em->contains($changed), 'a row the unit changed is detached');
        self::assertFalse($this->em->contains($added), 'a row the unit added is detached');
        self::assertFalse($this->em->contains($pointer), 'a row pointing at a detached one goes too');
        self::assertTrue($this->em->contains($kept), 'a row the unit did not change stays managed');
        self::assertTrue($this->em->contains($member));
        self::assertTrue($this->em->contains($reference), 'an uninitialised reference stays as it was');
        self::assertTrue($this->em->isOpen());
    }

    public function testAManagerClosedByAFailedFlushStaysClosedUntilTheBoundary(): void
    {
        $this->em->persist(new LoadAccount('taken'));
        $this->em->flush();
        $this->em->clear();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('closed entity manager'), self::callback(static fn (array $context): bool => $context['manager'] === 'default'));
        $runner = new AuthKitTransactionRunner($this->em, $this->em, $this->registry, 1, $logger);

        try {
            $runner->run(fn () => $this->em->persist(new LoadAccount('taken')));
            self::fail('the primary key is taken');
        } catch (Throwable $failure) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $failure);
        }

        self::assertFalse($this->em->isOpen(), 'loud for the rest of the request');
        self::assertSame($this->em, $this->registry->getManager('default'), 'never reset in the middle of the request');
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());

        $runner->reset();
        self::assertNotSame($this->em, $this->registry->getManager('default'));
        $fresh = $this->manager('default');
        self::assertTrue($fresh->isOpen());

        // An open manager is left alone at the next boundary.
        (new AuthKitTransactionRunner($fresh, null, $this->registry, 1, $logger))->reset();
        self::assertSame($fresh, $this->registry->getManager('default'));
    }

    public function testTheBoundaryLeavesManagersItCannotReplace(): void
    {
        $this->em->close();
        // No registry; a manager the registry does not know; AuthKit's private manager (it replaces itself).
        (new AuthKitTransactionRunner($this->em))->reset();
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $this->manager('other')]);
        $registry->expects(self::never())->method('resetManager');
        (new AuthKitTransactionRunner($this->em, null, $registry))->reset();
        (new AuthKitTransactionRunner(new AuthKitEntityManager($this->registry), null, $registry))->reset();

        self::assertFalse($this->em->isOpen());
    }

    public function testAUnitWithAccountsAlsoForgetsWhatItTouchedInTheApplicationsManager(): void
    {
        $application = $this->manager('other');
        $this->schema($application);
        $account = new LoadAccount('acc', 'Before');
        $application->persist($account);
        $application->flush();
        $pending = new LoadAccount('pending', 'host');
        $application->persist($pending);
        $runner = new AuthKitTransactionRunner($this->em, $application);

        $this->failing($runner, static function () use ($account, $application): never {
            $account->name = 'After';
            $application->flush();

            throw new RuntimeException('a grant refused');
        });

        self::assertFalse($application->contains($account), 'what the unit changed in the application\'s manager is detached');
        self::assertFalse($application->contains($pending), 'flushed inside the failed unit, so its state is not trusted');
        // The application's manager is never flushed by the runner itself.
        $later = new LoadAccount('later', 'pending');
        $application->persist($later);
        $runner->runWithAccounts(static fn (): null => null);
        self::assertTrue($application->getUnitOfWork()->isScheduledForInsert($later));
    }

    public function testAUnitOnTheConnectionFlushesNothing(): void
    {
        $pending = new LoadAccount('pending', 'host');
        $this->em->persist($pending);
        $runner = new AuthKitTransactionRunner($this->em);

        $runner->runOnConnection(function (): void {
            $this->em->getConnection()->insert('load_account', ['id' => 'dbal', 'name' => 'Row']);
        });

        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($pending), 'nothing of the application is flushed');
        self::assertSame('Row', $this->em->getConnection()->fetchOne("SELECT name FROM load_account WHERE id = 'dbal'"));

        $this->failing($runner, static function (): never {
            throw new RuntimeException('refused');
        });
        self::assertTrue($this->em->contains($pending), 'nothing the unit touched');
        self::assertInstanceOf(TransactionRunnerInterface::class, AuthKitTransactionRunner::forConnection($this->em->getConnection()));
    }

    public function testAnAuthKitRowChangedByAFailedUnitIsDetached(): void
    {
        $invitation = new Invitation('default', 'ada@example.com', null, 7, new DateTimeImmutable());
        $this->em->persist($invitation);
        $this->em->flush();

        $this->failing(new AuthKitTransactionRunner($this->em), static function () use ($invitation): never {
            $invitation->revoke('admin', new DateTimeImmutable());

            throw new RuntimeException('refused before the flush');
        });

        self::assertFalse($this->em->contains($invitation), 'its pending change belonged to the failed unit');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT revoked_at FROM auth_kit_invitation'));
    }

    private function failing(AuthKitTransactionRunner $runner, callable $work): void
    {
        try {
            $runner->runWithAccounts($work);
            self::fail('the unit must fail');
        } catch (RuntimeException) {
        }
    }

    private function manager(string $name): EntityManagerInterface
    {
        $manager = $this->registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function schema(EntityManagerInterface $manager): void
    {
        $classes = [$manager->getClassMetadata(LoadAccount::class), $manager->getClassMetadata(LoadMembership::class)];
        if (!$manager->getMetadataFactory()->isTransient(Invitation::class)) {
            $classes[] = $manager->getClassMetadata(Invitation::class);
        }
        (new SchemaTool($manager))->createSchema($classes);
    }
}
