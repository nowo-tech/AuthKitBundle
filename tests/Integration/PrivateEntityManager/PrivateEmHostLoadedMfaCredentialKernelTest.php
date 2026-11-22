<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotFoundException;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotManagedException;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaKernelTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaTestKernel;

/**
 * 1.55.0 (Q-W2-1) with `doctrine.private_entity_manager: true`: an MFA credential the application loaded through its own
 * manager is never inserted again by {@see MfaStore::save()} and is removed through AuthKit's instance by
 * {@see MfaStore::delete()}; the application's instance stays managed by the application.
 */
final class PrivateEmHostLoadedMfaCredentialKernelTest extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MfaTestKernel::class;
    }

    public function testSavingACredentialLoadedByTheApplicationIsRefused(): void
    {
        $loaded = $this->hostCredential();

        try {
            $this->store()->save($loaded);
            self::fail('A foreign instance must never be persisted as new.');
        } catch (AuthKitEntityNotManagedException $exception) {
            self::assertSame(MfaCredential::class, $exception->entityClass);
            self::assertSame((string) $loaded->getId(), $exception->id);
        }
        self::assertSame(1, $this->rows());
    }

    public function testSavingACredentialWhoseRowIsGoneIsRefused(): void
    {
        $loaded = $this->hostCredential();
        $this->em()->getConnection()->executeStatement('DELETE FROM ' . $this->table());

        $this->expectException(AuthKitEntityNotFoundException::class);
        $this->store()->save($loaded);
    }

    public function testDeletingACredentialLoadedByTheApplicationRemovesTheRow(): void
    {
        $loaded = $this->hostCredential();

        $this->store()->delete($loaded);

        self::assertSame(0, $this->rows());
        self::assertTrue($this->em()->contains($loaded), 'the application\'s instance is not detached');
        // Already gone: nothing left to remove, no error.
        $this->store()->delete($loaded);
        self::assertSame(0, $this->rows());
    }

    private function hostCredential(): MfaCredential
    {
        $manager = $this->manager();
        $secret  = $manager->newSecret();
        self::assertNotNull($manager->enable($this->user('member@example.com'), $secret, $this->code($secret)));
        $private = self::getContainer()->get(AuthKitEntityManager::class);
        self::assertInstanceOf(AuthKitEntityManager::class, $private);
        $private->live()->clear();

        $id         = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM ' . $this->table());
        $credential = $this->em()->find(MfaCredential::class, $id);
        self::assertInstanceOf(MfaCredential::class, $credential);

        return $credential;
    }

    private function store(): MfaStore
    {
        $store = self::getContainer()->get(MfaStore::class);
        self::assertInstanceOf(MfaStore::class, $store);

        return $store;
    }

    private function rows(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table());
    }

    private function table(): string
    {
        return $this->em()->getClassMetadata(MfaCredential::class)->getTableName();
    }
}
