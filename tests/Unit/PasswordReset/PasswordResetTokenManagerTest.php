<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManager;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccessor;

use function hash;
use function strlen;

final class PasswordResetTokenManagerTest extends TestCase
{
    public function testRejectsInvalidTokenBytes(): void
    {
        $this->expectException(LogicException::class);

        new PasswordResetTokenManager(
            $this->createMock(EntityManagerInterface::class),
            new PropertyAccessor(),
            new PasswordResetUserResolver($this->createMock(EntityManagerInterface::class), TestUser::class, 'email'),
            TestUser::class,
            'passwordResetToken',
            'passwordResetExpiresAt',
            3600,
            0,
            6,
            'numeric',
            'link',
        );
    }

    public function testCreateLinkToken(): void
    {
        $user    = new TestUser();
        $manager = $this->createManager('link', $this->entityManagerForPersist());

        $result = $manager->createForUser($user);

        self::assertSame(PasswordResetDeliveryMode::Link, $result->deliveryMode);
        self::assertNotSame('', $result->plainToken);
        self::assertNotNull($user->getPasswordResetToken());
        self::assertInstanceOf(DateTimeImmutable::class, $user->getPasswordResetExpiresAt());
    }

    public function testResolveUserByLinkToken(): void
    {
        $plain = 'abc123';
        $user  = new TestUser();
        $user->setPasswordResetToken(hash('sha256', $plain));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $manager = $this->createManager('link', $this->entityManagerForLinkResolve($user));

        self::assertSame($user, $manager->resolveUserByLinkToken($plain));
    }

    public function testResolveUserByLinkTokenReturnsNullWhenNotFound(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $manager = $this->createManager('link', $entityManager);

        self::assertNull($manager->resolveUserByLinkToken('invalid'));
    }

    public function testResolveUserByIdentifierAndCode(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $user->setPasswordResetToken(hash('sha256', '123456'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with(['email' => 'user@example.com'])->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(TestUser::class)->willReturn($repository);

        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            TestUser::class,
            'passwordResetToken',
            'passwordResetExpiresAt',
            3600,
            32,
            6,
            'numeric',
            'code',
        );

        self::assertSame($user, $manager->resolveUserByIdentifierAndCode('user@example.com', '123456'));
        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '000000'));
    }

    public function testResolveReturnsNullWhenExpired(): void
    {
        $user = new TestUser();
        $user->setPasswordResetToken(hash('sha256', 'abc'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('-1 hour'));

        $manager = $this->createManager('link', $this->entityManagerForLinkResolve($user));

        self::assertNull($manager->resolveUserByLinkToken('abc'));
    }

    public function testClearForUser(): void
    {
        $user = new TestUser();
        $user->setPasswordResetToken('hash');
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($user);
        $entityManager->expects(self::once())->method('flush');

        $manager = $this->createManager('link', $entityManager);
        $manager->clearForUser($user);

        self::assertNull($user->getPasswordResetToken());
        self::assertNull($user->getPasswordResetExpiresAt());
    }

    public function testBothDeliveryStoresCompositeHash(): void
    {
        $user    = new TestUser();
        $manager = $this->createManager('both', $this->entityManagerForPersist());
        $result  = $manager->createForUser($user);

        self::assertNotNull($result->linkToken());
        self::assertNotNull($result->code());
        self::assertStringContainsString('|', (string) $user->getPasswordResetToken());
    }

    public function testCreateCodeToken(): void
    {
        $user    = new TestUser();
        $manager = $this->createManager('code', $this->entityManagerForPersist());
        $result  = $manager->createForUser($user);

        self::assertSame(PasswordResetDeliveryMode::Code, $result->deliveryMode);
        self::assertSame(6, strlen($result->plainToken));
    }

    public function testResolveReturnsNullWhenStoredTokenIsNotString(): void
    {
        $user = new TestUser();
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            TestUser::class,
            'passwordResetToken',
            'passwordResetExpiresAt',
            3600,
            32,
            6,
            'numeric',
            'code',
        );

        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '123456'));
    }

    public function testResolveByCodeReturnsNullWhenUserNotFound(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            TestUser::class,
            'passwordResetToken',
            'passwordResetExpiresAt',
            3600,
            32,
            6,
            'numeric',
            'code',
        );

        self::assertNull($manager->resolveUserByIdentifierAndCode('missing@example.com', '123456'));
    }

    public function testResolveByLinkTokenReturnsNullWhenExpiryIsMissing(): void
    {
        $user = new TestUser();
        $user->setPasswordResetToken(hash('sha256', 'abc'));

        $manager = $this->createManager('link', $this->entityManagerForLinkResolve($user));

        self::assertNull($manager->resolveUserByLinkToken('abc'));
    }

    public function testGenerateNumericCode(): void
    {
        $manager = new PasswordResetTokenManager(
            $this->entityManagerForPersist(),
            new PropertyAccessor(),
            new PasswordResetUserResolver($this->createMock(EntityManagerInterface::class), TestUser::class, 'email'),
            TestUser::class,
            'passwordResetToken',
            'passwordResetExpiresAt',
            3600,
            32,
            6,
            'numeric',
            'code',
        );

        $result = $manager->createForUser(new TestUser());

        self::assertMatchesRegularExpression('/^\d{6}$/', $result->plainToken);
    }

    public function testGenerateAlphanumericCode(): void
    {
        $manager = new PasswordResetTokenManager(
            $this->entityManagerForPersist(),
            new PropertyAccessor(),
            new PasswordResetUserResolver($this->createMock(EntityManagerInterface::class), TestUser::class, 'email'),
            TestUser::class,
            'passwordResetToken',
            'passwordResetExpiresAt',
            3600,
            32,
            6,
            'alphanumeric',
            'code',
        );

        $result = $manager->createForUser(new TestUser());

        self::assertSame(6, strlen($result->plainToken));
        self::assertMatchesRegularExpression('/^[2-9A-Z]+$/', $result->plainToken);
    }

    public function testResolveCodeWithBothModeStoredHash(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $user->setPasswordResetToken(hash('sha256', 'link') . '|' . hash('sha256', '123456'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with(['email' => 'user@example.com'])->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            TestUser::class,
            'passwordResetToken',
            'passwordResetExpiresAt',
            3600,
            32,
            6,
            'numeric',
            'both',
        );

        self::assertSame($user, $manager->resolveUserByIdentifierAndCode('user@example.com', '123456'));
    }

    private function createManager(string $delivery, EntityManagerInterface $entityManager): PasswordResetTokenManager
    {
        return new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            TestUser::class,
            'passwordResetToken',
            'passwordResetExpiresAt',
            3600,
            32,
            6,
            'alphanumeric',
            $delivery,
        );
    }

    private function entityManagerForPersist(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        return $entityManager;
    }

    private function entityManagerForLinkResolve(TestUser $user): EntityManagerInterface
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($user);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->with('u')->willReturn($queryBuilder);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(TestUser::class)->willReturn($repository);
        $entityManager->method('persist');
        $entityManager->method('flush');

        return $entityManager;
    }
}
