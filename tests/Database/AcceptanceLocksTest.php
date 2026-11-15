<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\TransactionContract\TransientStorageFailure;

use function preg_match;
use function str_starts_with;

/**
 * 1.50.0 (Beacon spec 239), two sessions on MySQL 8.4 / PostgreSQL 16: an invitation acceptance is one unit of work of
 * AuthKit's transaction runner (up to 1.49 a hand-written transaction whose catch rolled back again). A deadlock inside
 * the account's nested flush, where MySQL already dropped the savepoint (1305), and a lock wait both answer
 * TransientStorageFailure (503) and leave the worker's connection at level 0; the invitation stays usable.
 */
final class AcceptanceLocksTest extends LoadDatabaseTestCase
{
    public function testADeadlockInTheAccountsNestedFlushIsTransientAndTheNextAcceptanceWorks(): void
    {
        [$invitation, $hash] = $this->invitation('ada@example.com');
        $id                  = (int) $this->connection()->fetchOne('SELECT id FROM auth_kit_invitation');
        $peer                = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        $this->ballast();
        // The peer writes the same account first, then (after a pause) wants the invitation row the acceptance claimed.
        self::assertSame('OK', $peer->execute("INSERT INTO kernel_users (email, password, roles, newsletter) VALUES ('ada@example.com', 'x', '[]', " . ($this->mysql() ? '0' : 'false') . ')'));
        $peer->send($this->sleepStatement(1.5));
        $peer->send('UPDATE auth_kit_invitation SET revoked_by = ' . "'peer'" . ' WHERE id = ' . $id);

        // The server picks the victim (MySQL: the lighter transaction, this one; PostgreSQL: the session whose deadlock
        // check runs first): exactly one side loses.
        $failed = false;
        try {
            $outcome = $this->get('load.acceptor', InvitationAcceptor::class)->attempt($invitation, $hash, ['email' => 'ada@example.com', 'password' => 'secret-123']);
            self::assertSame(InvitationAcceptance::ACCEPTED, $outcome->status);
        } catch (TransientStorageFailure $failure) {
            $failed = true;
            self::assertTrue($this->causedByDeadlock($failure), 'a deadlock, never the 1305 of the lost savepoint');
            self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $failure->getMessage(), 'no SQL in the message');
        }
        self::assertSame('OK', $peer->await(), 'the sleep');
        $peerAnswer = $peer->await();
        self::assertSame($failed, str_starts_with($peerAnswer, 'OK'), 'exactly one side lost the deadlock: ' . $peerAnswer);
        self::assertTrue($failed || preg_match('/^ERR (1213|\\d+ 40P01)/', $peerAnswer) === 1, $peerAnswer);
        self::assertSame('OK', $peer->execute('ROLLBACK'));
        $this->assertHealthy();
        self::assertSame($failed ? 0 : 1, $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE accepted_at IS NOT NULL'), 'all or nothing');
        self::assertSame($failed ? 0 : 1, $this->rows("SELECT COUNT(*) FROM kernel_users WHERE email = 'ada@example.com'"));

        if ($failed) {
            // The invitation stays usable: the next request of this worker accepts it on the same connection.
            $retry = $this->get('load.acceptor', InvitationAcceptor::class)->attempt($this->reloaded(), $hash, ['email' => 'ada@example.com', 'password' => 'secret-123']);
            self::assertSame(InvitationAcceptance::ACCEPTED, $retry->status);
            self::assertSame(1, $this->rows("SELECT COUNT(*) FROM kernel_users WHERE email = 'ada@example.com'"));
        }
        $this->assertHealthy();
    }

    public function testALockWaitOnTheInvitationIsTransientAndClaimsNothing(): void
    {
        [$invitation, $hash] = $this->invitation('ada@example.com');
        $peer                = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('SELECT id FROM auth_kit_invitation FOR UPDATE'));
        $this->shortLockWait();

        try {
            $this->get('load.acceptor', InvitationAcceptor::class)->attempt($invitation, $hash, ['email' => 'ada@example.com', 'password' => 'secret-123']);
            self::fail('the invitation row is locked by the peer');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $failure->getMessage());
        }
        $this->assertHealthy();
        self::assertSame('OK', $peer->execute('ROLLBACK'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM kernel_users'));
        self::assertSame(InvitationAcceptance::ACCEPTED, $this->get('load.acceptor', InvitationAcceptor::class)->attempt($this->reloaded(), $hash, ['email' => 'ada@example.com', 'password' => 'secret-123'])->status);
    }

    public function testTwoAcceptancesOfOneInvitationHaveOneWinner(): void
    {
        [$invitation, $hash] = $this->invitation('ada@example.com');
        $peer                = $this->otherSession();
        // A concurrent acceptance claims the invitation and commits a second later.
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute("UPDATE auth_kit_invitation SET accepted_at = '2026-11-15 10:00:00', accepted_by = 'peer' WHERE accepted_at IS NULL"));
        $peer->send($this->sleepStatement(1.0));
        $peer->send('COMMIT');

        $outcome = $this->get('load.acceptor', InvitationAcceptor::class)->attempt($invitation, $hash, ['email' => 'ada@example.com', 'password' => 'secret-123']);

        self::assertSame(InvitationAcceptance::NOT_ACCEPTED, $outcome->status, 'the claim waited for the peer and found the invitation used');
        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM kernel_users'), 'no account for a used invitation');
        self::assertSame('peer', $this->connection()->fetchOne('SELECT accepted_by FROM auth_kit_invitation'));
        $this->assertHealthy();
    }

    private function reloaded(): Invitation
    {
        $this->em->clear();
        $invitation = $this->em->getRepository(Invitation::class)->findOneBy([]);
        self::assertNotNull($invitation);

        return $invitation;
    }
}
