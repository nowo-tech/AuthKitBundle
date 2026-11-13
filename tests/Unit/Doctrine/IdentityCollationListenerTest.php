<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\IdentityCollationListener;
use Nowo\AuthKitBundle\Doctrine\PasswordResetCollationListener;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Tests\Integration\LookAlike\LookAlikeDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function method_exists;

/**
 * REQ-SEC-006 (1.46.1): the identity columns of the MFA, social, vault and invitation tables get the binary NO PAD
 * collation on MySQL / MariaDB, and the PAD SPACE fallback (MySQL 5.7, or a server_version without the patch number)
 * is logged.
 */
final class IdentityCollationListenerTest extends TestCase
{
    /** @return iterable<string, array{AbstractPlatform, string, bool}> */
    public static function platforms(): iterable
    {
        yield 'MariaDB' => [new MariaDBPlatform(), 'utf8mb4_nopad_bin', false];
        yield 'MySQL 8.4' => [new MySQL84Platform(), 'utf8mb4_0900_bin', false];
        yield 'MySQL 5.7 / server_version "8.0"' => [new MySQLPlatform(), 'utf8mb4_bin', true];
    }

    #[DataProvider('platforms')]
    public function testEveryIdentityColumnIsCollated(AbstractPlatform $platform, string $collation, bool $warns): void
    {
        $em     = LookAlikeDatabase::entityManager(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($warns ? self::exactly(4) : self::never())->method('warning')
            ->with(self::stringContains('PAD SPACE'), self::arrayHasKey('table'));
        $listener = new IdentityCollationListener($this->registry($this->managerOn($platform)), $logger);

        foreach (IdentityCollationListener::FIELDS as $class => $fields) {
            $metadata = $em->getClassMetadata($class);
            $schema   = (new SchemaTool($em))->getSchemaFromMetadata([$metadata]);
            $table    = $schema->getTable($metadata->getTableName());
            $args     = new GenerateSchemaTableEventArgs($metadata, $schema, $table);
            $listener->postGenerateSchemaTable($args);
            // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
            $result = method_exists(Table::class, 'edit') ? $args->getClassTable() : $table;
            foreach ($fields as $field) {
                $column = $metadata->getColumnName($field);
                self::assertSame($collation, $result->getColumn($column)->getPlatformOption('collation'), $class . '::' . $field);
                self::assertSame('utf8mb4', $result->getColumn($column)->getPlatformOption('charset'));
            }
            self::assertFalse($result->getColumn('id')->hasPlatformOption('collation'));
        }
    }

    public function testOtherEntitiesAndExactPlatformsAreUntouched(): void
    {
        $em     = LookAlikeDatabase::entityManager(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $metadata = $em->getClassMetadata(MfaCredential::class);
        $schema   = (new SchemaTool($em))->getSchemaFromMetadata([$metadata]);
        $table    = $schema->getTable($metadata->getTableName());

        $other = new GenerateSchemaTableEventArgs($em->getClassMetadata(QrLoginChallenge::class), new Schema(), new Table('qr'));
        (new IdentityCollationListener($this->registry($this->managerOn(new MySQLPlatform())), $logger))->postGenerateSchemaTable($other);
        self::assertFalse($other->classTableWasMutated());

        $sqlite = new GenerateSchemaTableEventArgs($metadata, $schema, $table);
        (new IdentityCollationListener($this->registry($this->managerOn(new SQLitePlatform())), $logger))->postGenerateSchemaTable($sqlite);
        self::assertFalse($sqlite->classTableWasMutated());
        (new IdentityCollationListener($this->registry(null), $logger))->postGenerateSchemaTable($sqlite);
        self::assertFalse($sqlite->classTableWasMutated());
        self::assertFalse($table->getColumn('user_id')->hasPlatformOption('collation'));
    }

    public function testThePasswordResetListenerWarnsOnTheFallbackToo(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('server_version'), ['table' => 't']);
        PasswordResetCollationListener::warnOnPadSpace('utf8mb4_bin', 't', $logger);
        PasswordResetCollationListener::warnOnPadSpace('utf8mb4_0900_bin', 't', $logger);
        PasswordResetCollationListener::warnOnPadSpace('utf8mb4_bin', 't', null);
    }

    private function managerOn(AbstractPlatform $platform): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        return $manager;
    }

    private function registry(?EntityManagerInterface $manager): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return $registry;
    }
}
