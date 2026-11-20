<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Transaction;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\HostFlushReporter;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity\LoadAccount;
use Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity\LoadMembership;
use Nowo\AuthKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Nowo\TransactionDoctrine\HostFlushProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function hash;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * 1.54.0 (REQ-ARCH-001 rule 6 deprecation layer): the host-flush probe in AuthKit's transaction runner, on a real
 * (SQLite) entity manager. Host mode (the runner flushes the application's manager) reports each foreign entity class
 * with pending changes once per unit of work; AuthKit's own entities, nested units and the private manager report
 * nothing; the reporter is read-only and forgets everything at the boundary.
 */
final class HostFlushReporterTest extends TestCase
{
    private const MESSAGE = 'Your pending changes to Nowo\\AuthKitBundle\\Tests\\Integration\\Transaction\\Entity\\LoadAccount were flushed by NowoAuthKitBundle; 2.0 no longer does.';

    private SqliteManagerRegistry $registry;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $deprecations = [];

    protected function setUp(): void
    {
        $this->registry = new SqliteManagerRegistry([
            'default' => [
                'Nowo\\AuthKitBundle\\Entity'                                  => dirname(__DIR__, 3) . '/src/Entity',
                'Nowo\\AuthKitBundle\\Tests\\Integration\\Transaction\\Entity' => __DIR__ . '/Entity',
            ],
        ]);
        $manager = $this->registry->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->em = $manager;
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(LoadAccount::class),
            $this->em->getClassMetadata(LoadMembership::class),
            $this->em->getClassMetadata(Invitation::class),
        ]);
        // Test only: restored in tearDown().
        set_error_handler(function (int $level, string $message): bool { // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
            $this->deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testAHostModeFlushReportsEachForeignClassOncePerUnitOfWork(): void
    {
        $reporter = new HostFlushReporter();
        $runner   = new AuthKitTransactionRunner($this->em, hostFlush: $reporter);
        $this->em->persist(new LoadAccount('a', 'pending'));
        $this->em->persist(new LoadAccount('b', 'pending'));

        $runner->run(fn () => $this->em->persist($this->invitation('ada@example.com')));
        // Still pending work of the same class in the same request: already reported.
        $this->em->persist(new LoadAccount('c', 'pending'));
        $runner->runWithAccounts(fn () => $this->em->persist($this->invitation('bob@example.com')));

        self::assertSame([self::MESSAGE], $this->deprecations);
        // Behaviour unchanged: the host's pending rows were written as before 1.54.
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM load_account'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM auth_kit_invitation'));
    }

    public function testTheChangesTheUnitLeavesForTheInnerFlushAreReportedToo(): void
    {
        $account = new LoadAccount('a', 'before');
        $this->em->persist($account);
        $this->em->flush();

        // A change made inside the unit and left for AuthKit's flush (a listener relying on it): 2.0 does not write it.
        (new AuthKitTransactionRunner($this->em, hostFlush: new HostFlushReporter()))->run(static function () use ($account): void {
            $account->name = 'after';
        });

        self::assertSame([self::MESSAGE], $this->deprecations);
        self::assertSame('after', $this->em->getConnection()->fetchOne("SELECT name FROM load_account WHERE id = 'a'"));
    }

    public function testAuthKitRowsAndAnEmptyUnitOfWorkReportNothing(): void
    {
        $runner = new AuthKitTransactionRunner($this->em, hostFlush: new HostFlushReporter());

        $runner->run(fn () => $this->em->persist($this->invitation('ada@example.com')));
        $runner->run(static fn (): null => null);

        self::assertSame([], $this->deprecations);
    }

    public function testOnlyTheOutermostUnitReports(): void
    {
        $reporter = new HostFlushReporter();
        $outer    = new AuthKitTransactionRunner($this->em, hostFlush: $reporter);
        $inner    = new AuthKitTransactionRunner($this->em, hostFlush: $reporter);

        // The account the outer unit changes is AuthKit's own work (a password it sets before the nested account write).
        $outer->runWithAccounts(function () use ($inner): void {
            $this->em->persist(new LoadAccount('a', 'written by AuthKit'));
            $inner->runWithAccounts(fn () => $this->em->flush());
        });

        self::assertSame([], $this->deprecations);
        self::assertTrue($reporter->enter(), 'the depth went back to zero');
        $reporter->leave();
    }

    public function testAFailedUnitLeavesTheDepthAtZero(): void
    {
        $reporter = new HostFlushReporter();
        $runner   = new AuthKitTransactionRunner($this->em, hostFlush: $reporter);

        try {
            $runner->run(static function (): never {
                throw new RuntimeException('refused');
            });
        } catch (RuntimeException $failure) {
            self::assertSame('refused', $failure->getMessage());
        }
        $reporter->leave(); // An extra leave never goes below zero.

        $this->em->persist(new LoadAccount('a', 'pending'));
        $runner->run(static fn (): null => null);

        self::assertSame([self::MESSAGE], $this->deprecations);
    }

    public function testThePrivateManagerReportsNothing(): void
    {
        $this->em->persist(new LoadAccount('a', 'pending'));
        $private = new AuthKitEntityManager($this->registry);

        (new AuthKitTransactionRunner($private, $this->em, hostFlush: new HostFlushReporter()))->run(static fn () => $private->persist(new Invitation('default', 'ada@example.com', null, 7, new DateTimeImmutable())));

        self::assertSame([], $this->deprecations);
        // Rule 6: the application's pending change stays pending.
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM load_account'));
        self::assertCount(1, $this->em->getUnitOfWork()->getScheduledEntityInsertions());
    }

    public function testTheBoundaryForgetsWhatWasReportedAndTheDepth(): void
    {
        $probe    = new HostFlushProbe();
        $reporter = new HostFlushReporter($probe);
        $runner   = new AuthKitTransactionRunner($this->em, hostFlush: $reporter);
        $reporter->enter(); // A unit whose leave() never came (a worker request cut short).

        $reporter->reset();
        $this->em->persist(new LoadAccount('a', 'first request'));
        $runner->run(static fn (): null => null);
        $reporter->reset();
        $this->em->persist(new LoadAccount('b', 'next request'));
        $runner->run(static fn (): null => null);

        self::assertSame([self::MESSAGE, self::MESSAGE], $this->deprecations, 'one report per request, none lost to a stale depth');
    }

    public function testTheReporterIsReadOnly(): void
    {
        $account = new LoadAccount('a', 'pending');
        $this->em->persist($account);

        (new HostFlushReporter())->beforeFlush($this->em);

        self::assertSame([self::MESSAGE], $this->deprecations);
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($account), 'still pending: nothing flushed');
        self::assertTrue($this->em->contains($account), 'nothing cleared or detached');
        self::assertTrue($this->em->isOpen());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM load_account'));
    }

    private function invitation(string $email): Invitation
    {
        $invitation = new Invitation('default', $email, null, 7, new DateTimeImmutable());
        $invitation->issue(hash('sha256', $email), 7, new DateTimeImmutable());

        return $invitation;
    }
}
