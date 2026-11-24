<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function microtime;
use function sprintf;

/**
 * 2.2.1 (REQ-RUNTIME-003 rule 4, "only one"), two sessions on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16: the single-use
 * writes of the second factor are conditional UPDATEs, so two requests that present the same one-time secret at the
 * same time never both pass.
 *
 * - a TOTP time step is accepted once (replay protection, `MfaStore::claimStep()`);
 * - a recovery code is used once (`MfaStore::useRecoveryCode()`);
 * - a trusted-device token is rotated once (`MfaStore::rotateDevice()`): the device cookie of the loser is refused.
 *
 * The other request (the peer session) runs the same statement and holds it: this request's statement waits on the row
 * lock and follows the peer's outcome (refused when the peer commits, accepted when it rolls back). A peer that
 * committed first always wins.
 */
#[Group('database')]
final class MfaSingleUseRaceTest extends LoadDatabaseTestCase
{
    private const CODE_HASH = 'recovery-code-hash';

    private const DEVICE_HASH = 'device-token-hash';

    private const STEP = 101;

    /**
     * @return iterable<string, array{'recovery code'|'totp step'|'trusted device', bool}>
     */
    public static function heldClaims(): iterable
    {
        foreach (['totp step', 'recovery code', 'trusted device'] as $write) {
            yield $write . ', the peer commits' => [$write, true];
            yield $write . ', the peer rolls back' => [$write, false];
        }
    }

    /**
     * @param 'recovery code'|'totp step'|'trusted device' $write
     */
    #[DataProvider('heldClaims')]
    public function testAUseWaitingOnAHeldUseFollowsItsOutcome(string $write, bool $commit): void
    {
        $credential = $this->enrolled();
        $peer       = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute($this->peerUse($write, (int) $credential->getId())));
        $peer->send($this->sleepStatement(1.0));
        $peer->send($commit ? 'COMMIT' : 'ROLLBACK');

        $started  = microtime(true);
        $accepted = $this->use($write, $credential);

        self::assertGreaterThan(0.5, microtime(true) - $started, 'the write waited for the peer');
        self::assertSame('OK', $peer->await(), 'the sleep');
        self::assertSame('OK', $peer->await(), 'the end of the peer use');
        self::assertSame(!$commit, $accepted, $commit ? 'the second use is refused' : 'the use goes through');
        $this->assertUsedOnce($write, (int) $credential->getId(), $commit);
        $this->assertHealthy();
    }

    /**
     * @return iterable<string, array{'recovery code'|'totp step'|'trusted device'}>
     */
    public static function writes(): iterable
    {
        yield 'totp step' => ['totp step'];
        yield 'recovery code' => ['recovery code'];
        yield 'trusted device' => ['trusted device'];
    }

    /**
     * @param 'recovery code'|'totp step'|'trusted device' $write
     */
    #[DataProvider('writes')]
    public function testAUseAfterTheOtherOneCommittedIsRefused(string $write): void
    {
        $credential = $this->enrolled();
        self::assertSame('OK', $this->otherSession()->execute($this->peerUse($write, (int) $credential->getId())));

        self::assertFalse($this->use($write, $credential), 'the secret was already used');
        $this->assertUsedOnce($write, (int) $credential->getId(), true);
        $this->assertHealthy();
    }

    private function store(): MfaStore
    {
        return new MfaStore($this->get('doctrine', ManagerRegistry::class));
    }

    /**
     * An enabled credential (last accepted step 100) with one recovery code and one trusted device.
     */
    private function enrolled(): MfaCredential
    {
        $store      = $this->store();
        $now        = new DateTimeImmutable();
        $credential = $store->credentialOrNew('default', new MfaOwner(KernelUser::class, 'ada@example.com'), $now);
        $credential->enable('JBSWY3DPEHPK3PXP', self::STEP - 1, $now);
        $store->save($credential);
        $store->replaceRecoveryCodes($credential, [self::CODE_HASH], $now);
        self::assertTrue($store->insertDevice($credential, 'device-public-id', self::DEVICE_HASH, 'Laptop', null, $now, $now->modify('+30 days')));

        return $credential;
    }

    /**
     * @param 'recovery code'|'totp step'|'trusted device' $write
     */
    private function use(string $write, MfaCredential $credential): bool
    {
        $store = $this->store();

        return match ($write) {
            'totp step'      => $store->claimStep($credential, self::STEP),
            'recovery code'  => $store->useRecoveryCode($credential, self::CODE_HASH, new DateTimeImmutable()),
            'trusted device' => $store->rotateDevice($credential, self::DEVICE_HASH, 'this-request-hash', new DateTimeImmutable()),
        };
    }

    /**
     * The statement AuthKit runs for the same use, from the other request.
     *
     * @param 'recovery code'|'totp step'|'trusted device' $write
     */
    private function peerUse(string $write, int $id): string
    {
        return match ($write) {
            'totp step'      => sprintf('UPDATE %s SET last_used_step = %d WHERE id = %d AND totp_secret IS NOT NULL AND (last_used_step IS NULL OR last_used_step < %d)', MfaCredential::TABLE, self::STEP, $id, self::STEP),
            'recovery code'  => sprintf("UPDATE %s SET used_at = '2026-11-24 10:00:00' WHERE mfa_id = %d AND code_hash = '%s' AND used_at IS NULL", MfaRecoveryCode::TABLE, $id, self::CODE_HASH),
            'trusted device' => sprintf("UPDATE %s SET token_hash = 'peer-request-hash' WHERE mfa_id = %d AND token_hash = '%s'", MfaTrustedDevice::TABLE, $id, self::DEVICE_HASH),
        };
    }

    /**
     * @param 'recovery code'|'totp step'|'trusted device' $write
     */
    private function assertUsedOnce(string $write, int $id, bool $peerWon): void
    {
        switch ($write) {
            case 'totp step':
                self::assertSame(self::STEP, $this->rows(sprintf('SELECT last_used_step FROM %s WHERE id = %d', MfaCredential::TABLE, $id)));
                break;
            case 'recovery code':
                self::assertSame(1, $this->rows(sprintf('SELECT COUNT(*) FROM %s WHERE mfa_id = %d AND used_at IS NOT NULL', MfaRecoveryCode::TABLE, $id)));
                self::assertSame(0, $this->rows(sprintf('SELECT COUNT(*) FROM %s WHERE mfa_id = %d AND used_at IS NULL', MfaRecoveryCode::TABLE, $id)));
                break;
            default:
                $token = (string) $this->connection()->fetchOne(sprintf('SELECT token_hash FROM %s WHERE mfa_id = %d', MfaTrustedDevice::TABLE, $id));
                self::assertSame($peerWon ? 'peer-request-hash' : 'this-request-hash', $token, 'one rotation of the device token');
                self::assertSame(1, $this->rows(sprintf('SELECT COUNT(*) FROM %s WHERE mfa_id = %d', MfaTrustedDevice::TABLE, $id)));
        }
    }
}
