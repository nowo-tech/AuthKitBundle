<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerSubscriber;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeConflictException;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

use function microtime;
use function sprintf;

/**
 * 2.2.1 (REQ-RUNTIME-003 rule 4, "only one"), two sessions on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16: a QR login
 * challenge changes state once. Every transition is a conditional `UPDATE … WHERE status = :from`
 * (`QrLoginChallengeRepository::transitionStatus()`), so:
 *
 * - two desktops polling one approved challenge sign in once (consume);
 * - an approval and a denial of one pending challenge never both apply.
 *
 * The other request (the peer session) runs the same transition and holds it: this request's transition waits on the
 * row lock and follows the peer's outcome (QrLoginChallengeConflictException when the peer commits, the transition when
 * it rolls back). A transition after the peer committed is refused.
 */
#[Group('database')]
final class QrLoginClaimRaceTest extends LoadDatabaseTestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function heldTransitions(): iterable
    {
        yield 'consume against consume, the peer commits' => ['consume', true];
        yield 'consume against consume, the peer rolls back' => ['consume', false];
        yield 'approve against deny, the peer commits' => ['approve', true];
        yield 'approve against deny, the peer rolls back' => ['approve', false];
    }

    #[DataProvider('heldTransitions')]
    public function testATransitionWaitingOnAHeldOneFollowsItsOutcome(string $action, bool $commit): void
    {
        [$challenge, $from, $peerTo, $to] = $this->prepared($action);
        $peer                             = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute($this->peerTransition($challenge->getId(), $from, $peerTo)));
        $peer->send($this->sleepStatement(1.0));
        $peer->send($commit ? 'COMMIT' : 'ROLLBACK');

        $started = microtime(true);
        $refused = !$this->act($action, $challenge);

        self::assertGreaterThan(0.5, microtime(true) - $started, 'the transition waited for the peer');
        self::assertSame('OK', $peer->await(), 'the sleep');
        self::assertSame('OK', $peer->await(), 'the end of the peer transition');
        self::assertSame($commit, $refused, $commit ? 'the second transition is refused' : 'the transition goes through');
        self::assertSame(($commit ? $peerTo : $to)->value, $this->storedStatus($challenge->getId()));
        $this->assertHealthy();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'consume' => ['consume'];
        yield 'approve' => ['approve'];
    }

    #[DataProvider('actions')]
    public function testATransitionAfterTheOtherOneCommittedIsRefused(string $action): void
    {
        [$challenge, $from, $peerTo] = $this->prepared($action);
        self::assertSame('OK', $this->otherSession()->execute($this->peerTransition($challenge->getId(), $from, $peerTo)));

        self::assertFalse($this->act($action, $challenge), 'the challenge already changed state');
        self::assertSame($peerTo->value, $this->storedStatus($challenge->getId()));
        $this->assertHealthy();
    }

    /**
     * A challenge in the state the action starts from, loaded as a new request would load it.
     *
     * The challenge, the state both requests start from, the peer's target state and this request's target state.
     *
     * @return array{QrLoginChallenge, QrLoginChallengeStatus, QrLoginChallengeStatus, QrLoginChallengeStatus}
     */
    private function prepared(string $action): array
    {
        $qr        = $this->qr();
        $challenge = $qr->create(Request::create('/login/qr'))['challenge'];
        if ($action === 'consume') {
            $qr->approve($challenge, $this->user(), null);
        }
        $this->get('load.boundary', PrivateEntityManagerSubscriber::class)->clearState();
        $loaded = $qr->find($challenge->getId());
        self::assertInstanceOf(QrLoginChallenge::class, $loaded);

        return $action === 'consume'
            ? [$loaded, QrLoginChallengeStatus::Approved, QrLoginChallengeStatus::Consumed, QrLoginChallengeStatus::Consumed]
            : [$loaded, QrLoginChallengeStatus::Pending, QrLoginChallengeStatus::Denied, QrLoginChallengeStatus::Approved];
    }

    /**
     * @return bool false when AuthKit refused the transition (another request changed the state first)
     */
    private function act(string $action, QrLoginChallenge $challenge): bool
    {
        try {
            $action === 'consume'
                ? $this->qr()->consume($challenge, $this->user())
                : $this->qr()->approve($challenge, $this->user(), null);
        } catch (QrLoginChallengeConflictException) {
            return false;
        }

        return true;
    }

    private function peerTransition(string $id, QrLoginChallengeStatus $from, QrLoginChallengeStatus $to): string
    {
        return sprintf("UPDATE auth_kit_qr_login_challenge SET status = '%s' WHERE id = '%s' AND status = '%s'", $to->value, $id, $from->value);
    }

    private function storedStatus(string $id): string
    {
        return (string) $this->connection()->fetchOne(sprintf("SELECT status FROM auth_kit_qr_login_challenge WHERE id = '%s'", $id));
    }

    private function user(): KernelUser
    {
        return (new KernelUser())->setEmail('ada@example.com')->setPassword('unused-password');
    }

    private function qr(): QrLoginChallengeManager
    {
        return $this->get('load.qr', QrLoginChallengeManager::class);
    }
}
