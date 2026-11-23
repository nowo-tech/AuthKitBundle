<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetCredentialClaimInterface;
use Nowo\AuthKitBundle\Tests\Integration\PasswordReset\Entity\ResetUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $h   = new ResetStorageHarness($storage);
        $ada = $h->addUser('ada@example.com');
        $h->manager->createForUser($ada);
        $claims = $h->manager;
        self::assertInstanceOf(PasswordResetCredentialClaimInterface::class, $claims);

        self::assertTrue($claims->claimForUser($ada));
        self::assertFalse($claims->claimForUser($ada), 'the second claim finds nothing');
        self::assertSame(0, $this->live($h, $storage));
    }

    public function testAUserEntityCredentialReplacedMeanwhileIsNotClaimed(): void
    {
        $h   = new ResetStorageHarness('user_entity');
        $ada = $h->addUser('ada@example.com');
        $h->manager->createForUser($ada);
        // Another request issued a new link after this one read the user.
        $h->em->getConnection()->executeStatement("UPDATE reset_users SET passwordResetToken = 'newer' WHERE email = 'ada@example.com'");
        $claims = $h->manager;
        self::assertInstanceOf(PasswordResetCredentialClaimInterface::class, $claims);

        self::assertFalse($claims->claimForUser($ada));
        self::assertSame('newer', $h->em->getConnection()->fetchOne("SELECT passwordResetToken FROM reset_users WHERE email = 'ada@example.com'"), 'the newer link stays usable');
    }

    public function testAClaimedUserFlushesNothingMoreForTheCredential(): void
    {
        $h   = new ResetStorageHarness('user_entity');
        $ada = $h->addUser('ada@example.com');
        $h->manager->createForUser($ada);
        $claims = $h->manager;
        self::assertInstanceOf(PasswordResetCredentialClaimInterface::class, $claims);

        self::assertTrue($claims->claimForUser($ada));
        self::assertInstanceOf(ResetUser::class, $ada);
        self::assertNull($ada->getPasswordResetToken());
        $h->em->getUnitOfWork()->computeChangeSets();
        self::assertSame([], $h->em->getUnitOfWork()->getEntityChangeSet($ada), 'the cleared values are the original data');
    }

    public function testAUserTheManagerDoesNotHoldIsClearedAsBefore(): void
    {
        $h   = new ResetStorageHarness('user_entity');
        $ada = new ResetUser('ada@example.com');
        $ada->setPasswordResetToken('hash');
        $claims = $h->manager;
        self::assertInstanceOf(PasswordResetCredentialClaimInterface::class, $claims);

        self::assertTrue($claims->claimForUser($ada), 'not guarded: cleared and accepted, as up to 2.1');
        self::assertNull($ada->getPasswordResetToken());
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
