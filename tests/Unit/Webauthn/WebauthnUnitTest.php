<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Webauthn;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Webauthn\WebauthnErasure;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The passkey entity as a read model and the store without a mapped entity (2.4.0).
 */
final class WebauthnUnitTest extends TestCase
{
    public function testTheEntityExposesItsColumns(): void
    {
        $created    = new DateTimeImmutable('2026-11-25 10:00:00');
        $used       = new DateTimeImmutable('2026-11-25 11:00:00');
        $credential = new WebauthnCredential('p1', 'default', 'App\\User', '7', 'hash', 'cred', 'handle', 'key', 3, ['usb'], 'aaguid', true, false, 'Key', $created, $used);
        self::assertNull($credential->getId());
        self::assertNull($credential->getRpId());
        self::assertSame('example.com', (new WebauthnCredential('p1', 'default', 'App\\User', '7', 'hash', 'cred', 'handle', 'key', 3, ['usb'], 'aaguid', true, false, 'Key', $created, $used, 'example.com'))->getRpId());
        self::assertSame(['p1', 'default', 'App\\User', '7', 'hash', 'cred', 'handle', 'key', 3, ['usb'], 'aaguid', true, false, 'Key', $created, $used], [
            $credential->getPublicId(),
            $credential->getProfile(),
            $credential->getUserType(),
            $credential->getUserId(),
            $credential->getCredentialIdHash(),
            $credential->getCredentialId(),
            $credential->getUserHandle(),
            $credential->getPublicKey(),
            $credential->getSignCount(),
            $credential->getTransports(),
            $credential->getAaguid(),
            $credential->isBackupEligible(),
            $credential->isBackedUp(),
            $credential->getLabel(),
            $credential->getCreatedAt(),
            $credential->getLastUsedAt(),
        ]);
    }

    public function testTheStoreNeedsTheMapping(): void
    {
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn(null);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('doctrine.register_mapping');
        (new WebauthnStore($doctrine))->hasTable();
    }

    public function testTheErasureFindsNothingWithoutTheMapping(): void
    {
        // 2.4.1 (audit ZBX-v-I2): the erasure is registered whatever the mode; without a mapped entity it erases nothing.
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn(null);
        $store = new WebauthnStore($doctrine);
        self::assertFalse($store->isMapped());
        self::assertSame(0, (new WebauthnErasure($store, new MfaOwnerResolver($doctrine)))->forgetUser(new InMemoryUser('ana@example.com', null)));
    }
}
