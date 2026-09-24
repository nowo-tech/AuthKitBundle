<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManager;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\PropertyAccess\PropertyAccessor;

use function hash;

/**
 * Two consecutive requests served by the same service instances, without kernel reset.
 */
final class PasswordResetTokenManagerWorkerTest extends TestCase
{
    public function testResetCodeIsReadFromDatabaseEvenWhenUserStaysInIdentityMap(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $user->setPasswordResetToken(hash('sha256', '111111'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $database = [
            'token'   => hash('sha256', '111111'),
            'expires' => new DateTimeImmutable('+1 hour'),
        ];

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('contains')->with($user)->willReturn(true);
        $entityManager->expects(self::exactly(6))->method('refresh')->with($user)->willReturnCallback(
            static function (TestUser $managed) use (&$database): void {
                $managed->setPasswordResetToken($database['token']);
                $managed->setPasswordResetExpiresAt($database['expires']);
            },
        );

        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['delivery' => 'code'],
        ]);
        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, $registry),
            $registry,
            new NativeClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );

        // Request 1: the code stored at that time is accepted.
        self::assertSame($user, $manager->resolveUserByIdentifierAndCode('user@example.com', '111111'));

        // Another worker issues a new code; this worker still holds the old values in memory.
        $database['token'] = hash('sha256', '222222');

        // Request 2: the old code is rejected and the new one accepted.
        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '111111'));
        self::assertSame($user, $manager->resolveUserByIdentifierAndCode('user@example.com', '222222'));
    }

    public function testClearedCodeIsNotAcceptedFromStaleIdentityMap(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $user->setPasswordResetToken(hash('sha256', '111111'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('contains')->willReturn(true);
        $entityManager->method('refresh')->willReturnCallback(static function (TestUser $managed): void {
            $managed->setPasswordResetToken(null);
            $managed->setPasswordResetExpiresAt(null);
        });

        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['delivery' => 'code'],
        ]);
        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, $registry),
            $registry,
            new NativeClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );

        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '111111'));
    }
}
