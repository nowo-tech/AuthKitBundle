<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\WebauthnSqlScript;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped SQLite passkey scripts (2.4.0) create exactly the mapped table, which the bundle then uses, and the down
 * script removes it. MySQL, MariaDB and PostgreSQL: tests/Database/WebauthnDatabaseTest.
 */
final class WebauthnSqlScriptsKernelTest extends WebauthnKernelTestCase
{
    #[Test]
    public function theScriptsCreateAndDropTheMappedTable(): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DROP TABLE ' . WebauthnCredential::TABLE);
        self::assertNotSame('', WebauthnSqlScript::differences($this->em()));

        WebauthnSqlScript::run($connection, 'up');
        self::assertSame('', WebauthnSqlScript::differences($this->em()));
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey(new SoftwareAuthenticator());
        self::assertCount(1, $this->passkeys()->passkeys($this->user('member@example.com')));

        WebauthnSqlScript::run($connection, 'down');
        self::assertFalse($connection->createSchemaManager()->tablesExist([WebauthnCredential::TABLE]));
        foreach (['mysql', 'mariadb', 'postgresql', 'sqlite'] as $platform) {
            self::assertNotSame([], WebauthnSqlScript::statements($platform, 'up'));
            self::assertSame(['DROP TABLE auth_kit_webauthn_credential'], WebauthnSqlScript::statements($platform, 'down'));
        }
    }
}
