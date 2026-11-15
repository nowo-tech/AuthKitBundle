<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;

use function microtime;
use function str_replace;

/**
 * 1.50.0 (Beacon spec 239), two sessions on MySQL 8.4 / PostgreSQL 16: two first enrolments of one owner. The second
 * one's insert waits for the first one's unique key, loses it in a savepoint and reads the first one's row with a lock
 * (the latest committed version, whatever its snapshot): one credential, no unique-key error, the manager open. Up to
 * 1.49 the ORM flush hit the key and closed the manager.
 */
final class MfaCredentialRaceTest extends LoadDatabaseTestCase
{
    public function testTwoFirstEnrolmentsOfOneOwnerShareOneCredential(): void
    {
        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute("INSERT INTO auth_kit_mfa (id, profile, user_type, user_id, created_at, updated_at) VALUES (1000, 'default', '" . $this->escaped(KernelUser::class) . "', 'ada@example.com', '2026-11-15 10:00:00', '2026-11-15 10:00:00')"));
        $peer->send($this->sleepStatement(1.0));
        $peer->send('COMMIT');

        $started    = microtime(true);
        $credential = (new MfaStore($this->get('doctrine', ManagerRegistry::class)))->credentialOrNew('default', new MfaOwner(KernelUser::class, 'ada@example.com'), new DateTimeImmutable());

        self::assertGreaterThan(0.5, microtime(true) - $started, 'the insert waited for the first enrolment');
        // An explicit id: the column may be an identity column or fed by a sequence (DBAL 3 on PostgreSQL).
        self::assertSame(1000, $credential->getId(), 'the first enrolment\'s row');
        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM auth_kit_mfa'));
        $this->assertHealthy();
    }

    private function escaped(string $class): string
    {
        return $this->mysql() ? str_replace('\\', '\\\\', $class) : $class;
    }
}
