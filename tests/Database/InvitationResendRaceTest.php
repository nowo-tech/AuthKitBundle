<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

/**
 * 2.0 (REQ-RUNTIME-003, audits XX-I1 / ZF-L1), two sessions on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16: a resend or a
 * revocation races an acceptance (the single-use claim, a conditional UPDATE on the old token hash) in every order.
 *
 * - The acceptance holds the row (claim not yet committed) while AuthKit's guarded UPDATE waits on the row lock: if the
 *   acceptance commits, the resend / revocation writes nothing and answers NOT_OPEN; if it rolls back, the resend /
 *   revocation goes through.
 * - The resend / revocation commits first: the acceptance's claim on the old hash then matches no row.
 *
 * Never both an accepted row and a new token or a revocation on it.
 */
final class InvitationResendRaceTest extends LoadDatabaseTestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function heldClaims(): iterable
    {
        yield 'resend while the claim commits' => ['resend', true];
        yield 'resend while the claim rolls back' => ['resend', false];
        yield 'revoke while the claim commits' => ['revoke', true];
        yield 'revoke while the claim rolls back' => ['revoke', false];
    }

    #[DataProvider('heldClaims')]
    public function testAnActionWaitingOnAHeldClaimFollowsItsOutcome(string $action, bool $commit): void
    {
        [, $hash] = $this->invitation('ada@example.com');
        $id       = (int) $this->connection()->fetchOne('SELECT id FROM auth_kit_invitation');
        $peer     = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute($this->claim($id, $hash)));
        // The peer keeps the row lock for a moment, then commits or rolls back its claim.
        $peer->send($this->sleepStatement(1.0));
        $peer->send($commit ? 'COMMIT' : 'ROLLBACK');

        $outcome = $this->act($action, $id);

        self::assertSame('OK', $peer->await(), 'the sleep');
        self::assertSame('OK', $peer->await(), 'the end of the claim');
        $accepted = $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE accepted_at IS NOT NULL');
        $revoked  = $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE revoked_at IS NOT NULL');
        $sameHash = $this->rows("SELECT COUNT(*) FROM auth_kit_invitation WHERE token_hash = '" . $hash . "'");
        if ($commit) {
            self::assertSame(InvitationOutcome::NOT_OPEN, $outcome->status);
            self::assertSame([1, 0, 1], [$accepted, $revoked, $sameHash], 'the acceptance wins, nothing written over it');
        } else {
            self::assertSame($action === 'resend' ? InvitationOutcome::SENT : InvitationOutcome::REVOKED, $outcome->status);
            self::assertSame([0, $action === 'revoke' ? 1 : 0, $action === 'revoke' ? 1 : 0], [$accepted, $revoked, $sameHash]);
        }
        $this->assertHealthy();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'resend' => ['resend'];
        yield 'revoke' => ['revoke'];
    }

    #[DataProvider('actions')]
    public function testAClaimAfterTheActionCommittedMatchesNothing(string $action): void
    {
        [, $hash] = $this->invitation('ada@example.com');
        $id       = (int) $this->connection()->fetchOne('SELECT id FROM auth_kit_invitation');

        self::assertSame($action === 'resend' ? InvitationOutcome::SENT : InvitationOutcome::REVOKED, $this->act($action, $id)->status);

        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute($this->claim($id, $hash)));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM auth_kit_invitation WHERE accepted_at IS NOT NULL'), 'the old link no longer accepts');
        $this->assertHealthy();
    }

    private function act(string $action, int $id): InvitationOutcome
    {
        $loaded = $this->get('load.invitations', InvitationRepository::class)->find($id);
        self::assertNotNull($loaded);
        $manager = $this->get('load.inviter', InvitationManager::class);

        return $action === 'resend' ? $manager->resend(null, $loaded) : $manager->revoke(null, $loaded);
    }

    /**
     * The acceptance's single-use claim (InvitationRepository::claim()), as the other request sends it.
     */
    private function claim(int $id, string $hash): string
    {
        return sprintf(
            "UPDATE auth_kit_invitation SET accepted_at = CURRENT_TIMESTAMP, accepted_by = 'ada@example.com' WHERE id = %d AND token_hash = '%s' AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP",
            $id,
            $hash,
        );
    }
}
