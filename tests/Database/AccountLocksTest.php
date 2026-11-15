<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Transaction\AuthKitNamedLock;
use Nowo\TransactionContract\TransientStorageFailure;
use RuntimeException;

use function microtime;
use function sprintf;
use function str_starts_with;

/**
 * 1.50.0 (Beacon spec 239), two sessions on MySQL 8.4 / PostgreSQL 16: every account creation runs under the named lock
 * of its identifier, so a concurrent creation of the same identifier waits for the first one's commit and its checks
 * (the account taken, the 1.47 case-variant guard of invitations) see it. Never two accounts for one identifier, never a
 * closed entity manager from a unique key, and a lock held too long is a transient failure (503).
 */
final class AccountLocksTest extends LoadDatabaseTestCase
{
    public function testASecondRegistrationOfOneIdentifierWaitsForTheFirstAndIsRefused(): void
    {
        $peer = $this->otherSession();
        $this->peerLocks('ada@example.com');
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute($this->insertUser('ada@example.com')));
        $peer->send($this->sleepStatement(1.0));
        $peer->send('COMMIT');
        $peer->send($this->unlock('ada@example.com'));

        $started = microtime(true);
        try {
            $this->get('load.registrar', UserRegistrar::class)->register(['email' => 'ada@example.com', 'password' => 'secret-123'], 'default');
            self::fail('the identifier was taken while this registration waited');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('same unique value', $exception->getMessage());
        }

        self::assertGreaterThan(0.5, microtime(true) - $started, 'it waited for the first creation');
        self::assertSame('OK', $peer->await(), 'the sleep');
        self::assertSame('OK', $peer->await(), 'the commit');
        self::assertSame('OK', $peer->await(), 'the unlock');
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM kernel_users WHERE email = 'ada@example.com'"));
        $this->assertHealthy();
    }

    public function testAnInvitedCaseVariantCreatedMeanwhileIsRefusedAndTheInvitationStaysUsable(): void
    {
        [$invitation, $hash] = $this->invitation('ada@example.com');
        $peer                = $this->otherSession();
        $this->peerLocks('Ada@Example.com');
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute($this->insertUser('Ada@Example.com')));
        $peer->send($this->sleepStatement(1.0));
        $peer->send('COMMIT');
        $peer->send($this->unlock('Ada@Example.com'));

        $outcome = $this->get('load.acceptor', InvitationAcceptor::class)->attempt($invitation, $hash, ['email' => 'ada@example.com', 'password' => 'secret-123']);

        self::assertSame(InvitationAcceptance::NOT_ACCEPTED, $outcome->status, 'SEC-004 CD L2: the case variant is an account of this identifier');
        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM kernel_users'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE accepted_at IS NOT NULL'));
        $this->assertHealthy();
    }

    public function testALockHeldTooLongIsATransientFailureAndWritesNothing(): void
    {
        $this->peerLocks('ada@example.com');
        $started = microtime(true);

        try {
            $this->get('load.registrar', UserRegistrar::class)->register(['email' => 'ada@example.com', 'password' => 'secret-123'], 'default');
            self::fail('the peer holds the account lock');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(1, $failure->retryAfterSeconds());
        }

        self::assertGreaterThanOrEqual(AuthKitNamedLock::TIMEOUT_SECONDS - 0.5, microtime(true) - $started);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM kernel_users'));
        $this->assertHealthy();
        self::assertSame('OK', $this->otherSession()->execute($this->unlock('ada@example.com')));
        self::assertSame('ada@example.com', $this->get('load.registrar', UserRegistrar::class)->register(['email' => 'ada@example.com', 'password' => 'secret-123'], 'default')->getUserIdentifier());
    }

    private function peerLocks(string $identifier): void
    {
        $answer = $this->otherSession()->value($this->mysql()
            ? sprintf("SELECT GET_LOCK('%s', 10)", AuthKitNamedLock::name($this->key($identifier)))
            : sprintf('SELECT pg_advisory_lock(%d)', AuthKitNamedLock::advisoryKey($this->key($identifier))));
        self::assertTrue(str_starts_with($answer, 'VAL'), $answer);
    }

    private function unlock(string $identifier): string
    {
        return $this->mysql()
            ? sprintf("SELECT RELEASE_LOCK('%s')", AuthKitNamedLock::name($this->key($identifier)))
            : sprintf('SELECT pg_advisory_unlock(%d)', AuthKitNamedLock::advisoryKey($this->key($identifier)));
    }

    private function key(string $identifier): string
    {
        return 'account:' . KernelUser::class . "\0" . UserIdentifierMatcher::normalize($identifier);
    }

    private function insertUser(string $email): string
    {
        return sprintf("INSERT INTO kernel_users (email, password, roles, newsletter) VALUES ('%s', 'x', '[]', %s)", $email, $this->mysql() ? '0' : 'false');
    }
}
