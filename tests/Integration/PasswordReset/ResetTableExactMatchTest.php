<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\PasswordResetCollationListener;
use Nowo\AuthKitBundle\PasswordReset\Entity\PasswordResetRequest;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestRow;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser;
use Nowo\AuthKitBundle\User\UserIds;
use PHPUnit\Framework\TestCase;

use function hash;
use function method_exists;

/**
 * Audit NV-M1 / NV-L1 / NV-L2 (1.46.0): the reset table is matched byte for byte whatever the database collation.
 * The table is re-created with SQLite `COLLATE RTRIM` (user id: `jose` = `jose `, like MySQL PAD SPACE) and
 * `COLLATE NOCASE` columns (`Ana` = `ana`, like a case-insensitive collation): the SQL equality then returns the
 * look-alike row, and the repository must drop it.
 */
final class ResetTableExactMatchTest extends TestCase
{
    public function testALaxCollationNeverReturnsOrDeletesALookAlikeUsersRow(): void
    {
        $h = $this->laxHarness();
        $h->requests->save($this->row('jose', 'jose@example.com', hash('sha256', 'victim')));
        self::assertSame(1, $h->count("SELECT COUNT(*) FROM {$h->resetTable()} WHERE user_id = 'jose '"), 'The emulated collation ignores trailing spaces (PAD SPACE).');

        self::assertNull($h->requests->findForUser(ResetUser::class, 'jose ', 'jose@example.com'), 'Another id never gets the row.');
        self::assertNotNull($h->requests->findForUser(ResetUser::class, 'jose', 'jose@example.com'));
        self::assertNull($h->requests->findForUser('Nowo\\AuthKitBundle\\Tests\\Integration\\PasswordReset\\Entity\\resetuser', 'jose', 'jose@example.com'), 'The class compares exactly too.');

        self::assertSame(0, $h->requests->delete(ResetUser::class, 'jose ', ['jose@example.com']), 'A look-alike delete removes nothing.');
        $h->requests->save($this->row('jose ', 'jose@example.com', hash('sha256', 'attacker')));
        self::assertSame(2, $h->count("SELECT COUNT(*) FROM {$h->resetTable()}"), "Saving the attacker's credential keeps the victim's.");
        self::assertSame(hash('sha256', 'victim'), $h->requests->findForUser(ResetUser::class, 'jose', 'jose@example.com')?->tokenHash);

        // Users without an id: by identifier, exactly.
        $h->requests->save($this->row(null, 'Ana@example.com', hash('sha256', 'ana')));
        self::assertNull($h->requests->findForUser(ResetUser::class, null, 'ana@example.com'));
        self::assertNotNull($h->requests->findForUser(ResetUser::class, null, 'Ana@example.com'));
        self::assertSame(0, $h->requests->delete(ResetUser::class, null, ['ana@example.com']));
        self::assertSame(1, $h->requests->delete(ResetUser::class, null, ['Ana@example.com']));
    }

    public function testATokenHashMatchesByteForByte(): void
    {
        $h    = $this->laxHarness();
        $hash = hash('sha256', 'token');
        $h->requests->save($this->row('1', 'ada@example.com', $hash));

        self::assertCount(1, $h->requests->findByTokenHash($hash));
        self::assertSame([], $h->requests->findByTokenHash(strtoupper($hash)));
    }

    public function testACodeOfALookAlikeUserNeverResetsTheVictim(): void
    {
        $h      = $this->laxHarness();
        $victim = $h->addUser('victim@example.com');
        $id     = (string) UserIds::of($victim);
        // A row stored for a look-alike id (e.g. "7 " or a case variant on a string id) must not be the victim's.
        $h->requests->save(new PasswordResetRequestRow('default', ResetUser::class, $id . ' ', 'victim@example.com', hash('sha256', '123456'), null, new DateTimeImmutable('2026-11-12 11:00:00'), new DateTimeImmutable('2026-11-12 10:00:00')));

        self::assertNull($h->manager->resolveUserByIdentifierAndCode('victim@example.com', '123456'));
    }

    public function testOnlyTheNewestCredentialOfAUserResolves(): void
    {
        $h   = new ResetStorageHarness('table');
        $ada = $h->addUser('ada@example.com');
        $id  = (string) UserIds::of($ada);
        foreach (['older', 'newer'] as $token) {
            // Two concurrent requests: both rows stored (no delete in between).
            $h->em->getConnection()->insert($h->resetTable(), [
                'profile'    => 'default', 'user_class' => ResetUser::class, 'user_id' => $id, 'user_identifier' => 'ada@example.com',
                'token_hash' => hash('sha256', $token), 'code_hash' => null, 'expires_at' => '2026-11-12 11:00:00', 'created_at' => '2026-11-12 10:00:00',
            ]);
        }

        self::assertNull($h->manager->resolveUserByLinkToken('older'));
        self::assertSame($ada, $h->manager->resolveUserByLinkToken('newer'));
    }

    public function testSavingPurgesExpiredRowsOfEveryUser(): void
    {
        $h = new ResetStorageHarness('table');
        $h->requests->save(new PasswordResetRequestRow('default', ResetUser::class, '1', 'old@example.com', hash('sha256', 'old'), null, new DateTimeImmutable('2026-11-12 09:00:00'), new DateTimeImmutable('2026-11-12 08:00:00')));
        $h->requests->save(new PasswordResetRequestRow('default', ResetUser::class, '2', 'live@example.com', hash('sha256', 'live'), null, new DateTimeImmutable('2026-11-12 10:30:00'), new DateTimeImmutable('2026-11-12 08:30:00')));
        self::assertSame(2, $h->count("SELECT COUNT(*) FROM {$h->resetTable()}"));

        $h->requests->save($this->row('3', 'new@example.com', hash('sha256', 'new')));
        self::assertSame(0, $h->count("SELECT COUNT(*) FROM {$h->resetTable()} WHERE user_identifier = 'old@example.com'"));
        self::assertSame(2, $h->count("SELECT COUNT(*) FROM {$h->resetTable()}"));
    }

    public function testTheCollationListenerMakesTheColumnsBinaryOnMysqlOnly(): void
    {
        self::assertSame('utf8mb4_nopad_bin', PasswordResetCollationListener::collationFor(new MariaDBPlatform()));
        self::assertSame('utf8mb4_0900_bin', PasswordResetCollationListener::collationFor(new MySQL80Platform()));
        self::assertNull(PasswordResetCollationListener::collationFor(new PostgreSQLPlatform()));
        self::assertNull(PasswordResetCollationListener::collationFor(new SQLitePlatform()));

        $h        = new ResetStorageHarness('table');
        $metadata = $h->em->getClassMetadata(PasswordResetRequest::class);
        $schema   = (new SchemaTool($h->em))->getSchemaFromMetadata([$metadata]);
        $table    = $schema->getTable($metadata->getTableName());

        $mysql = $this->createStub(EntityManagerInterface::class);
        $mysql->method('getConnection')->willReturn($this->connectionOn(new MySQLPlatform()));
        $listener = new PasswordResetCollationListener($this->registry($mysql));
        $args     = new GenerateSchemaTableEventArgs($metadata, $schema, $table);
        $listener->postGenerateSchemaTable($args);
        // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
        $result = method_exists(Table::class, 'edit') ? $args->getClassTable() : $table;
        foreach (['user_id', 'user_identifier', 'token_hash', 'code_hash'] as $column) {
            self::assertSame('utf8mb4_bin', $result->getColumn($column)->getPlatformOption('collation'), $column);
        }
        self::assertFalse($result->getColumn('profile')->hasPlatformOption('collation'));

        // Another entity, or a platform that is already exact: untouched.
        $other = new GenerateSchemaTableEventArgs($h->em->getClassMetadata(ResetUser::class), new Schema(), new Table('reset_user'));
        $listener->postGenerateSchemaTable($other);
        self::assertFalse($other->classTableWasMutated());
        $sqlite = new GenerateSchemaTableEventArgs($metadata, $schema, $table);
        (new PasswordResetCollationListener($this->registry($h->em)))->postGenerateSchemaTable($sqlite);
        self::assertFalse($sqlite->classTableWasMutated());
        (new PasswordResetCollationListener($this->registry(null)))->postGenerateSchemaTable($sqlite);
        self::assertFalse($sqlite->classTableWasMutated());
        $plain = new Table('t');
        $plain->addColumn('x', 'string');
        self::assertNull(PasswordResetCollationListener::collate($plain, 'x', 'utf8mb4_bin', false));
        self::assertSame('utf8mb4_bin', $plain->getColumn('x')->getPlatformOption('collation'));
    }

    private function laxHarness(): ResetStorageHarness
    {
        $h     = new ResetStorageHarness('table');
        $table = $h->resetTable();
        $c     = $h->em->getConnection();
        $c->executeStatement('DROP TABLE ' . $table);
        $c->executeStatement('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, profile VARCHAR(64) NOT NULL, user_class VARCHAR(255) NOT NULL COLLATE NOCASE, user_id VARCHAR(191) DEFAULT NULL COLLATE RTRIM, user_identifier VARCHAR(255) NOT NULL COLLATE NOCASE, token_hash VARCHAR(64) NOT NULL COLLATE NOCASE, code_hash VARCHAR(64) DEFAULT NULL COLLATE NOCASE, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL)');

        return $h;
    }

    private function row(?string $id, string $identifier, string $tokenHash): PasswordResetRequestRow
    {
        return new PasswordResetRequestRow('default', ResetUser::class, $id, $identifier, $tokenHash, null, new DateTimeImmutable('2026-11-12 11:00:00'), new DateTimeImmutable('2026-11-12 10:00:00'));
    }

    private function registry(?EntityManagerInterface $manager): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return $registry;
    }

    private function connectionOn(AbstractPlatform $platform): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }
}
