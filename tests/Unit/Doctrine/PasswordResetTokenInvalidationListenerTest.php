<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Nowo\AuthKitBundle\Doctrine\PasswordResetTokenInvalidationListener;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** SEC-004 L6: a pending reset credential dies with the identifier or password it was issued for. */
final class PasswordResetTokenInvalidationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function changeSets(): iterable
    {
        yield 'password changed elsewhere' => [['password' => ['old', 'new']], true];
        yield 'identifier changed elsewhere' => [['email' => ['a@example.com', 'b@example.com']], true];
        yield 'reset flow writes the token itself' => [['password' => ['old', 'new'], 'passwordResetToken' => ['h', null]], false];
        yield 'unrelated change' => [['phone' => [null, '+34']], false];
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    #[DataProvider('changeSets')]
    public function testClearsThePendingCredentialOnlyForOutOfFlowChanges(array $changeSet, bool $cleared): void
    {
        $user = new TestUser('a@example.com');
        $user->setPasswordResetToken('pending-hash');
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $this->flush($user, $changeSet, $cleared);

        self::assertSame($cleared ? null : 'pending-hash', $user->getPasswordResetToken());
        self::assertSame($cleared, !$user->getPasswordResetExpiresAt() instanceof DateTimeImmutable);
    }

    public function testIgnoresUsersWithoutPendingCredentialAndOtherEntities(): void
    {
        $user = new TestUser('a@example.com');
        $this->flush($user, ['password' => ['old', 'new']], false);
        self::assertNull($user->getPasswordResetToken());

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(false);
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityUpdates')->willReturn([new stdClass(), $user]);
        $uow->expects(self::never())->method('recomputeSingleEntityChangeSet');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturn($metadata);

        (new PasswordResetTokenInvalidationListener(ProfileRegistryFactory::single(TestUser::class)))->onFlush(new OnFlushEventArgs($em));
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    private function flush(TestUser $user, array $changeSet, bool $expectRecompute): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getFieldValue')->willReturnCallback(static fn (TestUser $u, string $f): mixed => $f === 'passwordResetToken' ? $u->getPasswordResetToken() : null);
        $metadata->method('setFieldValue')->willReturnCallback(static function (TestUser $u, string $f, mixed $v): void {
            $f === 'passwordResetToken' ? $u->setPasswordResetToken(null) : $u->setPasswordResetExpiresAt(null);
        });

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityUpdates')->willReturn([$user]);
        $uow->method('getEntityChangeSet')->willReturn($changeSet);
        $uow->expects($expectRecompute ? self::once() : self::never())->method('recomputeSingleEntityChangeSet');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturn($metadata);

        (new PasswordResetTokenInvalidationListener(ProfileRegistryFactory::single(TestUser::class)))->onFlush(new OnFlushEventArgs($em));
    }
}
