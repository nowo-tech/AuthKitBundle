<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Transaction;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitRows;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Tests\Integration\Transaction\Entity\LoadToken;
use Nowo\AuthKitBundle\Tests\Support\SqliteManagerRegistry;
use PHPUnit\Framework\TestCase;

use function dirname;
use function str_starts_with;

/**
 * 1.50.0 (Beacon spec 239): a new AuthKit row whose unique key another request may take first is written in a savepoint;
 * a lost insert rolls back the savepoint only (the transaction and the entity manager go on).
 */
final class AuthKitRowsTest extends TestCase
{
    private SqliteManagerRegistry $registry;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->registry = new SqliteManagerRegistry(['default' => [
            'Nowo\\AuthKitBundle\\Entity'                                  => dirname(__DIR__, 3) . '/src/Entity',
            'Nowo\\AuthKitBundle\\Tests\\Integration\\Transaction\\Entity' => __DIR__ . '/Entity',
        ]]);
        $em = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(QrLoginChallenge::class), $em->getClassMetadata(MfaCredential::class), $em->getClassMetadata(LoadToken::class), $em->getClassMetadata(Invitation::class)]);
    }

    public function testALostInsertRollsBackItsSavepointOnly(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        self::assertNull(AuthKitRows::insert($this->em, $this->challenge('id-1', 'CODE0001')));
        $lost = AuthKitRows::insert($this->em, $this->challenge('id-2', 'CODE0001'));
        self::assertInstanceOf(UniqueConstraintViolationException::class, $lost);
        self::assertNull(AuthKitRows::insert($this->em, $this->challenge('id-3', 'CODE0003')));
        $connection->commit();

        self::assertSame(['id-1', 'id-3'], $connection->fetchFirstColumn('SELECT id FROM auth_kit_qr_login_challenge ORDER BY id'));
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->contains($this->challenge('id-1', 'X')), 'never managed by the insert');
    }

    public function testIdsFromTheDatabaseOrAGeneratorAreAssigned(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        self::assertNull(AuthKitRows::insert($this->em, new MfaCredential('default', 'App\\User', '7', new DateTimeImmutable())));
        $token = new LoadToken('one');
        self::assertNull(AuthKitRows::insert($this->em, $token));
        $connection->commit();

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM auth_kit_mfa'));
        self::assertTrue(str_starts_with((string) $token->id, 'gen-'));
        self::assertSame($token->id, $connection->fetchOne('SELECT id FROM load_token'));
    }

    public function testThePrivateManagerRecordsTheWrite(): void
    {
        $private    = new AuthKitEntityManager($this->registry);
        $connection = $private->getConnection();
        $connection->beginTransaction();
        self::assertNull(AuthKitRows::insert($private, $this->challenge('id-9', 'CODE0009')));
        $private->connectionRolledBack(1);
        $connection->rollBack();

        self::assertTrue($private->isStale(), 'a rollback of the level it wrote in makes the private manager stale');
    }

    private function challenge(string $id, string $code): QrLoginChallenge
    {
        return new QrLoginChallenge($id, $code, 'c', 'i', 'u', 'l', 'a', new DateTimeImmutable('+90 seconds'));
    }
}
