<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EntityManagerRecoveryTest extends TestCase
{
    public function testFlushDelegatesToEntityManager(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        (new EntityManagerRecovery())->flush($entityManager);
    }

    public function testRunReturnsCallbackResult(): void
    {
        self::assertSame(42, (new EntityManagerRecovery())->run(static fn (): int => 42));
    }

    public function testFailureWithoutRegistryIsRethrown(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException(new RuntimeException('boom'));

        $this->expectExceptionMessage('boom');
        (new EntityManagerRecovery())->flush($entityManager);
    }

    public function testFailureResetsOnlyClosedEntityManagers(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->method('flush')->willThrowException(new RuntimeException('boom'));

        $open = $this->createMock(EntityManagerInterface::class);
        $open->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([
            'default' => $closed,
            'audit'   => $open,
            'odm'     => $this->createMock(ObjectManager::class),
        ]);
        $registry->expects(self::once())->method('resetManager')->with('default');

        try {
            (new EntityManagerRecovery($registry))->flush($closed);
            self::fail('The flush exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }
    }
}
