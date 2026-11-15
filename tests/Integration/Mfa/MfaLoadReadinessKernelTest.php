<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Mfa\Event\MfaTrustedDeviceAddedEvent;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Mfa\MfaTrustedDevices;
use Nowo\AuthKitBundle\Mfa\MfaUnavailableException;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * 1.50.0 (Beacon spec 239): every change of a second factor runs under the credential's row lock and works on the row
 * as stored (read again under the lock), so a concurrent change is never undone or half applied; a credential removed
 * meanwhile is no change at all.
 */
final class MfaLoadReadinessKernelTest extends MfaKernelTestCase
{
    public function testTheOwnersRowIsWrittenOnceAndThenFound(): void
    {
        $store = $this->store();
        $owner = new MfaOwner(KernelUser::class, 'member@example.com');
        $first = $store->credentialOrNew('default', $owner, new DateTimeImmutable());
        $this->em()->clear();

        $again = $store->credentialOrNew('default', $owner, new DateTimeImmutable());

        self::assertSame($first->getId(), $again->getId());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM auth_kit_mfa'));
        self::assertTrue($this->em()->isOpen());
    }

    public function testRecoveryCodesAreNotRegeneratedForAFactorDisabledMeanwhile(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->enrol();
        $user = $this->user('member@example.com');
        self::assertNotNull($this->manager()->credential($user));
        // Another request disabled the factor after this one loaded it.
        $this->em()->getConnection()->executeStatement('UPDATE auth_kit_mfa SET enabled_at = NULL, totp_secret = NULL');

        $codes = $this->em()->getConnection()->fetchFirstColumn('SELECT code_hash FROM auth_kit_mfa_recovery_code ORDER BY id');

        self::assertSame([], $this->manager()->regenerateRecoveryCodes($user));
        self::assertSame($codes, $this->em()->getConnection()->fetchFirstColumn('SELECT code_hash FROM auth_kit_mfa_recovery_code ORDER BY id'), 'nothing replaced');
    }

    public function testATrustedDeviceIsStoredOnlyWhileTheFactorIsEnabled(): void
    {
        $store      = $this->store();
        $credential = $store->credentialOrNew('default', new MfaOwner(KernelUser::class, 'member@example.com'), new DateTimeImmutable());
        $now        = new DateTimeImmutable();

        self::assertFalse($store->insertDevice($credential, 'PUB1', 'hash-1', 'Firefox', null, $now, $now->modify('+1 day')), 'not enabled');
        $credential->enable('SECRETSECRETSECRET', 1, $now);
        $store->save($credential);
        self::assertTrue($store->insertDevice($credential, 'PUB2', 'hash-2', 'Firefox', null, $now, $now->modify('+1 day')));
        self::assertSame(['PUB2'], $this->em()->getConnection()->fetchFirstColumn('SELECT public_id FROM auth_kit_mfa_trusted_device'));
    }

    /**
     * Audit QG-L3: a device the store refused (the factor was disabled meanwhile) is not trusted: no cookie, no event.
     */
    public function testABrowserIsNotTrustedWhenTheFactorWasDisabledMeanwhile(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $this->enrol();
        $user = $this->user('member@example.com');
        self::assertNotNull($this->manager()->credential($user));
        $this->em()->getConnection()->executeStatement('UPDATE auth_kit_mfa SET enabled_at = NULL, totp_secret = NULL');
        $devices = self::getContainer()->get(MfaTrustedDevices::class);
        self::assertInstanceOf(MfaTrustedDevices::class, $devices);
        $request = new Request();

        self::assertNull($devices->trust($request, $user));
        self::assertFalse($request->attributes->has(MfaTrustedDevices::QUEUE_ATTRIBUTE), 'no cookie');
        self::assertSame([], $this->eventsOf(MfaTrustedDeviceAddedEvent::class), 'no event');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM auth_kit_mfa_trusted_device'));
    }

    public function testAChangeOfACredentialRemovedMeanwhileChangesNothing(): void
    {
        $store      = $this->store();
        $credential = $store->credentialOrNew('default', new MfaOwner(KernelUser::class, 'member@example.com'), new DateTimeImmutable());
        $this->em()->getConnection()->executeStatement('DELETE FROM auth_kit_mfa');

        // Still managed: the locked re-read finds no row.
        self::assertNull($store->locked($credential, static fn (): string => 'changed'));
        self::assertFalse($this->em()->contains($credential));
        // Detached: looked up again by id, gone.
        self::assertNull($store->locked($credential, static fn (): string => 'changed'));
    }

    public function testAChangeOfADetachedCredentialWorksOnTheStoredRow(): void
    {
        $store      = $this->store();
        $credential = $store->credentialOrNew('default', new MfaOwner(KernelUser::class, 'member@example.com'), new DateTimeImmutable());
        $this->em()->detach($credential);

        $seen = $store->locked($credential, static fn (MfaCredential $locked): MfaCredential => $locked);

        self::assertInstanceOf(MfaCredential::class, $seen);
        self::assertNotSame($credential, $seen);
        self::assertSame($credential->getId(), $seen->getId());
    }

    public function testAnOwnerWithoutUsableIdIsRefusedBeforeAnyWrite(): void
    {
        $this->expectException(MfaUnavailableException::class);
        $this->manager()->enable(new KernelUser(), 'SECRET', '000000');
    }

    private function store(): MfaStore
    {
        $store = self::getContainer()->get(MfaStore::class);
        self::assertInstanceOf(MfaStore::class, $store);

        return $store;
    }
}
