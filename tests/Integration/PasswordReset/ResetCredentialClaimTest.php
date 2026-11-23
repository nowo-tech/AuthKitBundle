<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset;

use Doctrine\Persistence\Mapping\MappingException;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCredentialClaimInterface;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Stub\UnmappedResetUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_map;
use function hash;

/**
 * 2.2.0 (REQ-RUNTIME-003): the single-use claim of both reset credential stores, on SQLite (the two-session races are in
 * tests/Database/PasswordResetClaimRaceTest). A claim clears the credential once; the second claim, a claim of a
 * credential replaced meanwhile, or one without a credential is refused and writes nothing.
 */
final class ResetCredentialClaimTest extends TestCase
{
    /**
     * @return iterable<string, array{'table'|'user_entity'}>
     */
    public static function storages(): iterable
    {
        yield 'user entity' => ['user_entity'];
        yield 'table' => ['table'];
    }

    /**
     * @param 'table'|'user_entity' $storage
     */
    #[DataProvider('storages')]
    public function testAClaimUsesTheCredentialUpOnce(string $storage): void
    {
        $h      = new ResetStorageHarness($storage);
        $ada    = $h->addUser('ada@example.com');
        $link   = (string) $h->manager->createForUser($ada)->linkToken();
        $claims = $this->claims($h);

        self::assertFalse($claims->claimForUser($ada, 'not-the-link'), 'a credential that proves nothing claims nothing');
        self::assertTrue($claims->claimForUser($ada, $link));
        self::assertFalse($claims->claimForUser($ada, $link), 'the second claim finds nothing');
        self::assertSame(0, $this->live($h, $storage));
    }

    /**
     * Audit L1: an older link never claims the credential a newer reset request stored.
     *
     * @param 'table'|'user_entity' $storage
     */
    #[DataProvider('storages')]
    public function testANewerCredentialIsNotClaimedByAnOlderLink(string $storage): void
    {
        $h     = new ResetStorageHarness($storage);
        $ada   = $h->addUser('ada@example.com');
        $older = (string) $h->manager->createForUser($ada)->linkToken();
        $h->clock->modify('+10 minutes');
        $newer  = (string) $h->manager->createForUser($ada)->linkToken();
        $claims = $this->claims($h);

        self::assertFalse($claims->claimForUser($ada, $older));
        self::assertSame(1, $this->live($h, $storage), 'the newer link stays usable');
        self::assertTrue($claims->claimForUser($ada, $newer));
    }

    public function testAUserEntityCredentialReplacedMeanwhileIsNotClaimed(): void
    {
        $h    = new ResetStorageHarness('user_entity');
        $ada  = $h->addUser('ada@example.com');
        $link = (string) $h->manager->createForUser($ada)->linkToken();
        // Another request issued a new link after this one read the user.
        $h->em->getConnection()->executeStatement("UPDATE reset_users SET passwordResetToken = 'newer' WHERE email = 'ada@example.com'");

        self::assertFalse($this->claims($h)->claimForUser($ada, $link));
        self::assertSame('newer', $h->em->getConnection()->fetchOne("SELECT passwordResetToken FROM reset_users WHERE email = 'ada@example.com'"), 'the newer link stays usable');
    }

    public function testAClaimedUserFlushesNothingMoreForTheCredential(): void
    {
        $h    = new ResetStorageHarness('user_entity');
        $ada  = $h->addUser('ada@example.com');
        $link = (string) $h->manager->createForUser($ada)->linkToken();

        self::assertTrue($this->claims($h)->claimForUser($ada, $link));
        self::assertInstanceOf(ResetUser::class, $ada);
        self::assertNull($ada->getPasswordResetToken());
        $h->em->getUnitOfWork()->computeChangeSets();
        self::assertSame([], $h->em->getUnitOfWork()->getEntityChangeSet($ada), 'the cleared values are the original data');
    }

    /** Audit L2: a user the manager does not hold (a host user directory's object) is claimed by id, bound the same way. */
    public function testAUserTheManagerDoesNotHoldIsClaimedById(): void
    {
        $h    = new ResetStorageHarness('user_entity');
        $ada  = $h->addUser('ada@example.com');
        $link = (string) $h->manager->createForUser($ada)->linkToken();
        $h->em->clear();
        self::assertFalse($h->em->contains($ada));

        self::assertTrue($this->claims($h)->claimForUser($ada, $link));
        self::assertFalse($this->claims($h)->claimForUser($ada, $link), 'the second claim finds nothing');
        self::assertSame(0, $this->live($h, 'user_entity'));
    }

    public function testAUserWithoutAnIdIsClearedAsBefore(): void
    {
        $h   = new ResetStorageHarness('user_entity');
        $ada = new ResetUser('ada@example.com');
        $ada->setPasswordResetToken(hash('sha256', 'link'));

        self::assertTrue($this->claims($h)->claimForUser($ada, 'link'), 'not addressable: cleared and accepted, as up to 2.1');
        self::assertNull($ada->getPasswordResetToken());
    }

    /**
     * Audit ZZ-A-L1: two concurrent reset requests left two rows (A older, B newer). Completing with B also removes A,
     * so A's link never becomes the newest again; a row stored after B is kept.
     */
    public function testATableClaimRemovesOlderRowsAndKeepsNewerOnes(): void
    {
        $h     = new ResetStorageHarness('table');
        $ada   = $h->addUser('ada@example.com');
        $older = (string) $h->manager->createForUser($ada)->linkToken();
        $rowA  = $h->em->getConnection()->fetchAssociative('SELECT * FROM ' . $h->resetTable());
        self::assertIsArray($rowA);
        $h->clock->modify('+1 minute');
        $link = (string) $h->manager->createForUser($ada)->linkToken();
        // The concurrent request's row, as it was before the second request deleted it.
        $h->em->getConnection()->insert($h->resetTable(), $rowA);
        $h->clock->modify('+1 minute');
        $rowC = $h->em->getConnection()->fetchAssociative('SELECT * FROM ' . $h->resetTable() . ' WHERE id = (SELECT MAX(id) FROM ' . $h->resetTable() . ')');
        self::assertIsArray($rowC);
        $rowC['id']         = (int) $rowC['id'] + 10;
        $rowC['token_hash'] = hash('sha256', 'newer-link');
        $rowC['code_hash']  = null;
        $h->em->getConnection()->insert($h->resetTable(), $rowC);
        self::assertSame(3, $this->live($h, 'table'));

        self::assertTrue($this->claims($h)->claimForUser($ada, $link));
        self::assertSame([(int) $rowC['id']], array_map('intval', $h->em->getConnection()->fetchFirstColumn('SELECT id FROM ' . $h->resetTable())), 'A is gone with B, the newer row stays');
        self::assertFalse($this->claims($h)->claimForUser($ada, $older), 'A no longer works');
    }

    /** Audit ZZ-A-L2: a Doctrine proxy of the user (a classic `__CG__` proxy class) is claimed by id, not cleared unguarded. */
    public function testAProxyOfTheUserIsClaimedById(): void
    {
        $h    = new ResetStorageHarness('user_entity');
        $ada  = $h->addUser('ada@example.com');
        $link = (string) $h->manager->createForUser($ada)->linkToken();
        self::assertInstanceOf(ResetUser::class, $ada);
        $proxy = new \NowoRegistryTestProxies\__CG__\Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser('ada@example.com');
        (new ReflectionProperty(ResetUser::class, 'id'))->setValue($proxy, $ada->getId());
        $proxy->setPasswordResetToken($ada->getPasswordResetToken());

        self::assertTrue($this->claims($h)->claimForUser($proxy, $link));
        self::assertFalse($this->claims($h)->claimForUser($proxy, $link), 'claimed once: the guarded UPDATE ran, not the 2.1 clear');
        self::assertSame(0, $this->live($h, 'user_entity'));
    }

    /**
     * A user AuthKit's manager cannot map (here a subclass the mapping does not know) is not addressable: the claim takes
     * the 2.1 path (clear and persist through that manager), which this manager refuses.
     */
    public function testAUserOfAClassTheManagerDoesNotMapTakesThe21Path(): void
    {
        $h   = new ResetStorageHarness('user_entity');
        $ada = new UnmappedResetUser('ada@example.com');
        $ada->setPasswordResetToken(hash('sha256', 'link'));

        $this->expectException(MappingException::class);
        $this->claims($h)->claimForUser($ada, 'link');
    }

    private function claims(ResetStorageHarness $h): PasswordResetCredentialClaimInterface
    {
        self::assertInstanceOf(PasswordResetCredentialClaimInterface::class, $h->manager);

        return $h->manager;
    }

    /**
     * @param 'table'|'user_entity' $storage
     */
    private function live(ResetStorageHarness $h, string $storage): int
    {
        return $storage === 'table'
            ? $h->count('SELECT COUNT(*) FROM ' . $h->resetTable())
            : $h->count('SELECT COUNT(*) FROM reset_users WHERE passwordResetToken IS NOT NULL');
    }
}
