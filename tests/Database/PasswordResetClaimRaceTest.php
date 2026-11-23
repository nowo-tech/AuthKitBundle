<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\PasswordReset\Entity\PasswordResetRequest;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetLinkUsedException;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Tests\Database\Support\DatabaseTestKernel;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use function sprintf;

/**
 * 2.2.0 (REQ-RUNTIME-003), two sessions on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16: two completions of one password
 * reset link race for its single-use credential. The other request (the peer session) claims the credential the way
 * AuthKit does and stores its own password; this request already checked the link.
 *
 * - The peer holds the claim while this request's claim waits on the row lock: if the peer commits, this request is
 *   refused (PasswordResetLinkUsedException: the reset page shows "invalid link") and the stored password is the
 *   peer's; if the peer rolls back, this request stores its password.
 * - The peer committed before this request claims: refused, the peer's password stays.
 *
 * Never a second password stored through one link (2.1: the last completion won). Both credential stores: on the user
 * entity (the default) and in the auth_kit_password_reset table.
 */
#[Group('database')]
final class PasswordResetClaimRaceTest extends LoadDatabaseTestCase
{
    private const PEER_HASH = 'peer-password-hash';

    private const EMAIL = 'ada@example.com';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function heldClaims(): iterable
    {
        yield 'user entity, the peer commits' => ['entity', true];
        yield 'user entity, the peer rolls back' => ['entity', false];
        yield 'table, the peer commits' => ['table', true];
        yield 'table, the peer rolls back' => ['table', false];
    }

    #[DataProvider('heldClaims')]
    public function testACompletionWaitingOnAHeldClaimFollowsItsOutcome(string $storage, bool $commit): void
    {
        $this->useStorage($storage);
        $link = $this->userWithResetLink();
        $user = $this->store()->resolveUserByLinkToken($link);
        self::assertInstanceOf(KernelUser::class, $user, 'this request checked the link');

        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute($this->peerClaim($storage)));
        self::assertSame('OK', $peer->execute(sprintf("UPDATE kernel_users SET password = '%s' WHERE email = '%s'", self::PEER_HASH, self::EMAIL)));
        $peer->send($this->sleepStatement(1.0));
        $peer->send($commit ? 'COMMIT' : 'ROLLBACK');

        $refused = false;

        try {
            $this->completer()->complete($user, 'new-password-of-this-request', $link);
        } catch (PasswordResetLinkUsedException) {
            $refused = true;
        }

        self::assertSame('OK', $peer->await(), 'the sleep');
        self::assertSame('OK', $peer->await(), 'the end of the peer claim');
        self::assertSame($commit, $refused, $commit ? 'the second completion is refused' : 'the completion goes through');
        $stored = (string) $this->connection()->fetchOne(sprintf("SELECT password FROM kernel_users WHERE email = '%s'", self::EMAIL));
        if ($commit) {
            self::assertSame(self::PEER_HASH, $stored, 'the password of the request that used the link stays');
        } else {
            self::assertNotSame(self::PEER_HASH, $stored);
            self::assertTrue($this->hasher()->isPasswordValid($this->reload(), 'new-password-of-this-request'));
        }
        self::assertSame(0, $this->liveCredentials($storage), 'the link is used up');
        $this->assertHealthy();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storages(): iterable
    {
        yield 'user entity' => ['entity'];
        yield 'table' => ['table'];
    }

    #[DataProvider('storages')]
    public function testACompletionAfterTheOtherOneCommittedIsRefused(string $storage): void
    {
        $this->useStorage($storage);
        $link = $this->userWithResetLink();
        $user = $this->store()->resolveUserByLinkToken($link);
        self::assertInstanceOf(KernelUser::class, $user);

        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute($this->peerClaim($storage)));
        self::assertSame('OK', $peer->execute(sprintf("UPDATE kernel_users SET password = '%s' WHERE email = '%s'", self::PEER_HASH, self::EMAIL)));

        try {
            $this->completer()->complete($user, 'new-password-of-this-request', $link);
            self::fail('The used link stored a second password.');
        } catch (PasswordResetLinkUsedException) {
        }

        self::assertSame(self::PEER_HASH, (string) $this->connection()->fetchOne(sprintf("SELECT password FROM kernel_users WHERE email = '%s'", self::EMAIL)));
        $this->assertHealthy();
    }

    /**
     * Audit L1: C1 and C2 both checked link A; C1 completes; a new reset request stores link B; C2's claim of A must
     * not use up B: C2 is refused, B stays usable, the stored password is C1's.
     */
    #[DataProvider('storages')]
    public function testAnOlderLinkNeverUsesUpTheCredentialOfANewerRequest(string $storage): void
    {
        $this->useStorage($storage);
        $link = $this->userWithResetLink();
        $user = $this->store()->resolveUserByLinkToken($link);
        self::assertInstanceOf(KernelUser::class, $user, 'C2 checked link A');

        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute($this->peerClaim($storage)));
        self::assertSame('OK', $peer->execute(sprintf("UPDATE kernel_users SET password = '%s' WHERE email = '%s'", self::PEER_HASH, self::EMAIL)));
        $newer = $this->newerRequest($storage);

        try {
            $this->completer()->complete($user, 'new-password-of-this-request', $link);
            self::fail('An older link used up the credential of a newer request.');
        } catch (PasswordResetLinkUsedException) {
        }

        self::assertSame(self::PEER_HASH, (string) $this->connection()->fetchOne(sprintf("SELECT password FROM kernel_users WHERE email = '%s'", self::EMAIL)));
        self::assertSame(1, $this->liveCredentials($storage), 'the newer credential stays');
        $this->em->clear();
        self::assertInstanceOf(KernelUser::class, $this->store()->resolveUserByLinkToken($newer), 'link B still resolves');
        $this->assertHealthy();
    }

    /**
     * Audit ZZ-A-L1: two concurrent reset requests of one user left two rows in the reset table (A, then B). Completing
     * with B removes A too: A's link and code never become the newest credential again.
     */
    public function testCompletingTheNewestTableRowRemovesAnOlderConcurrentOne(): void
    {
        $this->useStorage('table');
        $older = $this->userWithResetLink();
        $rowA  = $this->connection()->fetchAssociative('SELECT * FROM ' . PasswordResetRequest::TABLE);
        self::assertIsArray($rowA);
        $user = $this->em->find(KernelUser::class, self::EMAIL);
        self::assertInstanceOf(KernelUser::class, $user);
        $newer = $this->store()->createForUser($user)->linkToken();
        self::assertIsString($newer);
        // The other request's row, committed concurrently (its own transaction did not see B's delete), restored as it was.
        $this->connection()->insert(PasswordResetRequest::TABLE, $rowA);
        self::assertSame(2, $this->liveCredentials('table'));
        $this->em->clear();
        $resolved = $this->store()->resolveUserByLinkToken($newer);
        self::assertInstanceOf(KernelUser::class, $resolved);

        $this->completer()->complete($resolved, 'new-password-of-this-request', $newer);

        self::assertSame(0, $this->liveCredentials('table'), 'the older concurrent row is gone too');
        $this->em->clear();
        self::assertNull($this->store()->resolveUserByLinkToken($older), 'the older link does not work again');
        $this->assertHealthy();
    }

    #[DataProvider('storages')]
    public function testOneCompletionUsesTheLinkUp(string $storage): void
    {
        $this->useStorage($storage);
        $link = $this->userWithResetLink();
        $user = $this->store()->resolveUserByLinkToken($link);
        self::assertInstanceOf(KernelUser::class, $user);

        $this->completer()->complete($user, 'new-password-of-this-request', $link);

        self::assertTrue($this->hasher()->isPasswordValid($this->reload(), 'new-password-of-this-request'));
        self::assertSame(0, $this->liveCredentials($storage));
        self::assertNull($this->store()->resolveUserByLinkToken($link), 'the link no longer resolves');
        $this->assertHealthy();
    }

    private function useStorage(string $storage): void
    {
        if ($storage !== 'table') {
            return;
        }
        $this->kernel->shutdown();
        $this->kernel = new DatabaseTestKernel('test_table', true);
        $this->kernel->boot();
        $this->em = $this->get('doctrine.orm.entity_manager', EntityManagerInterface::class);
        // The reset table is mapped only when a profile stores its credentials there.
        (new SchemaTool($this->em))->updateSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * A user and a live reset link, issued through the store under test.
     */
    private function userWithResetLink(): string
    {
        $user = (new KernelUser())->setEmail(self::EMAIL)->setPassword('old-hash');
        $this->em->persist($user);
        $this->em->flush();
        $link = $this->store()->createForUser($user)->linkToken();
        self::assertIsString($link);
        $this->em->clear();

        return $link;
    }

    /**
     * The claim of the other completion for each store (the user's id is its e-mail; the test has one user).
     */
    private function peerClaim(string $storage): string
    {
        return $storage === 'table'
            ? sprintf('DELETE FROM %s', PasswordResetRequest::TABLE) // the only user's rows
            : sprintf("UPDATE kernel_users SET passwordResetToken = NULL, passwordResetExpiresAt = NULL WHERE email = '%s' AND passwordResetToken IS NOT NULL", self::EMAIL);
    }

    /**
     * A new reset request of the same user, as another request sends it: its own kernel, manager and connection.
     */
    private function newerRequest(string $storage): string
    {
        $other = new DatabaseTestKernel($storage === 'table' ? 'test_table' : 'test', true);
        $other->boot();
        try {
            $manager = $other->getContainer()->get('doctrine.orm.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $user = $manager->find(KernelUser::class, self::EMAIL);
            self::assertInstanceOf(KernelUser::class, $user);
            $store = $other->getContainer()->get('load.reset_store');
            self::assertInstanceOf(PasswordResetTokenManagerInterface::class, $store);
            $link = $store->createForUser($user)->linkToken();
            self::assertIsString($link);

            return $link;
        } finally {
            $other->shutdown();
        }
    }

    private function liveCredentials(string $storage): int
    {
        return $storage === 'table'
            ? $this->rows(sprintf('SELECT COUNT(*) FROM %s', PasswordResetRequest::TABLE))
            : $this->rows('SELECT COUNT(*) FROM kernel_users WHERE passwordResetToken IS NOT NULL');
    }

    private function reload(): KernelUser
    {
        $this->em->clear();
        $user = $this->em->find(KernelUser::class, self::EMAIL);
        self::assertInstanceOf(KernelUser::class, $user);

        return $user;
    }

    private function store(): PasswordResetTokenManagerInterface
    {
        return $this->get('load.reset_store', PasswordResetTokenManagerInterface::class);
    }

    private function completer(): PasswordResetCompleter
    {
        return $this->get('load.reset', PasswordResetCompleter::class);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return $this->get('load.hasher', UserPasswordHasherInterface::class);
    }
}
