<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Transaction\AccountLock;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function array_values;
use function count;

/**
 * 1.50.0 (Beacon spec 239): the account locks fold the identifier (NFC, lower case) in the user class, so every spelling
 * of one identifier waits for the same lock.
 */
final class AccountLockTest extends TestCase
{
    /** @var list<string> */
    private array $names = [];

    public function testOneIdentifierOneLockWhateverItsCase(): void
    {
        $runner  = $this->runner();
        $profile = ProfileRegistryFactory::single(TestUser::class)->getDefault();

        self::assertSame('a', AccountLock::forIdentifier($runner, $profile, 'Ada@Example.com', static fn (): string => 'a'));
        AccountLock::forIdentifier($runner, $profile, 'ada@example.com', static fn (): null => null);
        AccountLock::forIdentifier($runner, $profile, 'bob@example.com', static fn (): null => null);
        AccountLock::forUserClass($runner, $profile, static fn (): null => null);
        AccountLock::forSocialIdentity($runner, 'google', 'sub-1', TestUser::class, static fn (): null => null);

        self::assertSame($this->names[0], $this->names[1]);
        self::assertCount(5, $this->names);
        self::assertSame(4, count(array_values(array_unique($this->names))));
    }

    public function testNoIdentifierNoLock(): void
    {
        $runner  = $this->runner();
        $profile = ProfileRegistryFactory::single(TestUser::class)->getDefault();

        self::assertSame(1, AccountLock::forIdentifier($runner, $profile, null, static fn (): int => 1));
        self::assertSame(2, AccountLock::forIdentifier($runner, $profile, '', static fn (): int => 2));
        self::assertSame([], $this->names);
    }

    private function runner(): AuthKitTransactionRunner
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params): string {
            if ($sql === 'SELECT GET_LOCK(?, ?)') {
                $this->names[] = (string) $params[0];
            }

            return '1';
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new AuthKitTransactionRunner($em);
    }
}
