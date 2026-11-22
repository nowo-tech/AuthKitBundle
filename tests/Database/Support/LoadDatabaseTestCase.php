<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerSubscriber;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationTokens;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function assert;
use function ltrim;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_starts_with;

use const PHP_URL_PATH;

/**
 * 1.50.0 (Beacon spec 239): the real-database load tests, on the server of AUTH_KIT_LOAD_DATABASE_URL (MySQL 8.4 or
 * PostgreSQL 16 in CI). The test's own connection is AuthKit's (a booted test kernel); a second session in another
 * process holds locks against it. Use a throwaway database: the setup drops everything in it and refuses a name with no
 * `test`, `race`, `load` or `throwaway` part. Without the variable the tests are skipped.
 */
abstract class LoadDatabaseTestCase extends TestCase
{
    protected DatabaseTestKernel $kernel;

    protected EntityManagerInterface $em;

    protected ?SecondConnection $other = null;

    protected function setUp(): void
    {
        $url = DatabaseTestKernel::databaseUrl();
        if ($url === null) {
            self::markTestSkipped('Set AUTH_KIT_LOAD_DATABASE_URL to a throwaway server database (MySQL or PostgreSQL).');
        }
        $database = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (preg_match('/(?:^|_)(?:test|race|load|throwaway)(?:_|$)/', $database) !== 1) {
            throw new RuntimeException(sprintf('Refusing to use database "%s": the setup drops everything in it.', $database));
        }
        $this->kernel = new DatabaseTestKernel('test', true);
        $this->kernel->boot();
        $this->em = $this->get('doctrine.orm.entity_manager', EntityManagerInterface::class);
        $tool     = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->connection()->executeStatement('CREATE TABLE load_ballast (id INT NOT NULL PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    protected function mysql(): bool
    {
        return str_starts_with((string) DatabaseTestKernel::databaseUrl(), 'mysql');
    }

    protected function connection(): Connection
    {
        return $this->em->getConnection();
    }

    protected function otherSession(): SecondConnection
    {
        return $this->other ??= new SecondConnection((string) DatabaseTestKernel::databaseUrl());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function get(string $id, string $class): object
    {
        $service = $this->kernel->getContainer()->get($id);
        assert($service instanceof $class);

        return $service;
    }

    /**
     * A pending invitation and the hash of its link.
     *
     * @return array{Invitation, string}
     */
    protected function invitation(string $email): array
    {
        $hash       = (new InvitationTokens())->hash('token-' . $email);
        $now        = new DateTimeImmutable();
        $invitation = new Invitation('default', $email, null, 7, $now);
        $invitation->issue($hash, 7, $now);
        $this->get('load.invitations', InvitationRepository::class)->save($invitation);

        return [$invitation, $hash];
    }

    /**
     * The test's connection gives up waiting for a row lock after about a second (lock wait timeout 1205 on MySQL,
     * lock_not_available 55P03 on PostgreSQL): both are transient failures.
     */
    protected function shortLockWait(): void
    {
        $this->connection()->executeStatement($this->mysql() ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");
    }

    protected function sleepStatement(float $seconds): string
    {
        return $this->mysql() ? sprintf('SELECT SLEEP(%F)', $seconds) : sprintf('SELECT pg_sleep(%F)', $seconds);
    }

    /**
     * Makes the second session the heavier transaction, so a MySQL deadlock picks the test's connection as the victim.
     */
    protected function ballast(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            self::assertSame('OK', $this->otherSession()->execute('INSERT INTO load_ballast (id) VALUES (' . $i . ')'));
        }
    }

    /**
     * What the worker's next request finds after a failure, once the unit-of-work boundary ran (AuthKit replaces a
     * manager a failed write closed there, never mid-request): no transaction left open, the manager open, nothing
     * pending.
     */
    protected function assertHealthy(): void
    {
        $this->get('load.boundary', PrivateEntityManagerSubscriber::class)->clearState();
        self::assertSame(0, $this->connection()->getTransactionNestingLevel(), 'no transaction left open');
        $manager = $this->get('doctrine.orm.entity_manager', EntityManagerInterface::class);
        self::assertTrue($manager->isOpen(), 'the entity manager is open');
        self::assertSame([], $manager->getUnitOfWork()->getScheduledEntityInsertions(), 'nothing pending');
    }

    protected function causedByDeadlock(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException) {
                return true;
            }
        }

        return false;
    }

    protected function rows(string $sql): int
    {
        return (int) $this->connection()->fetchOne($sql);
    }
}
