<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;

use function getenv;
use function is_string;
use function parse_url;

use const PHP_URL_SCHEME;

/**
 * HP-L1: a `LoginSuccessEvent` listener swallows a duplicate key during a social sign-in. PostgreSQL aborts the
 * transaction, where COMMIT would roll back without an error and the sign-in would stay: it must count as failed. MySQL
 * and SQLite carry on: the link and the replaced password are written. The CI job `real-databases` runs this on both real
 * servers; the plain suite runs it on SQLite.
 */
final class SwallowedDatabaseErrorKernelTest extends ProgrammaticLoginKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return SwallowedDatabaseErrorKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS ' . SwallowingDatabaseListener::TABLE);
        $connection->executeStatement('CREATE TABLE ' . SwallowingDatabaseListener::TABLE . ' (id INT PRIMARY KEY)');
        $connection->executeStatement('INSERT INTO ' . SwallowingDatabaseListener::TABLE . ' (id) VALUES (1)');
    }

    public function testASwallowedDatabaseError(): void
    {
        $platform = $this->em()->getConnection()->getDatabasePlatform();
        $url      = getenv('AUTH_KIT_TEST_DB_URL');
        $expected = match (is_string($url) && $url !== '' ? parse_url($url, PHP_URL_SCHEME) : 'sqlite') {
            'postgresql' => PostgreSQLPlatform::class,
            'mysql'      => AbstractMySQLPlatform::class,
            default      => SQLitePlatform::class,
        };
        self::assertInstanceOf($expected, $platform, 'The test runs on the database it was asked for.');
        $listener = self::getContainer()->get(SwallowingDatabaseListener::class);
        self::assertInstanceOf(SwallowingDatabaseListener::class, $listener);
        $listener->armed = true;

        $this->socialCallback();
        $listener->armed = false;

        if ($platform instanceof PostgreSQLPlatform) {
            self::assertResponseStatusCodeSame(500);
            self::assertNull($this->token(), 'No success with an aborted transaction.');
            $this->assertAdaKeepsHerPasswordAndHasNoLink();

            return;
        }

        self::assertResponseRedirects('/home');
        self::assertSame('ada@example.com', $this->token()?->getUserIdentifier());
        $this->em()->clear();
        self::assertNotSame('old-password-123', $this->user()->getPassword(), 'The password is replaced, as on any first link.');
        self::assertSame(1, $this->em()->getRepository(SocialLoginAccount::class)->count([]));
    }
}
