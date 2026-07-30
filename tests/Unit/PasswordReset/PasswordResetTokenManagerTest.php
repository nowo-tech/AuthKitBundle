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
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\ParentTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\PropertyAccess\PropertyAccessor;

use function hash;
use function strlen;

final class PasswordResetTokenManagerTest extends TestCase
{
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

        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '123456'));
    }

    public function testResolveByCodeReturnsNullWhenUserNotFound(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

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
        $manager = $this->createManager('code', $this->entityManagerForPersist(), [
            'password_reset' => ['code_charset' => 'numeric'],
        ]);

        $result = $manager->createForUser(new TestUser());

        self::assertMatchesRegularExpression('/^\d{6}$/', $result->plainToken);
    }

    public function testGenerateAlphanumericCode(): void
    {
        $manager = $this->createManager('code', $this->entityManagerForPersist(), [
            'password_reset' => ['code_charset' => 'alphanumeric'],
        ]);

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

        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['delivery' => 'both'],
        ]);

        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, $registry),
            $registry,
            new NativeClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );

        self::assertSame($user, $manager->resolveUserByIdentifierAndCode('user@example.com', '123456'));
    }

    public function testResolveCodeWithBothModeRejectsWrongCode(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $user->setPasswordResetToken(hash('sha256', 'link') . '|' . hash('sha256', '123456'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->with(['email' => 'user@example.com'])->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['delivery' => 'both'],
        ]);

        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, $registry),
            $registry,
            new NativeClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );

        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '000000'));
    }

    public function testCreateForUserThrowsWhenProfileMissing(): void
    {
        $registry = ProfileRegistryFactory::single(TestUser::class);
        $manager  = new PasswordResetTokenManager(
            $this->entityManagerForPersist(),
            new PropertyAccessor(),
            new PasswordResetUserResolver($this->createMock(EntityManagerInterface::class), $registry),
            $registry,
            new NativeClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );

        $this->expectException(LogicException::class);

        $manager->createForUser(new ParentTestUser());
    }

    public function testResolveUserByLinkTokenWithNamedProfile(): void
    {
        $plain = 'abc123';
        $user  = new TestUser();
        $user->setPasswordResetToken(hash('sha256', $plain));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['delivery' => 'link'],
        ]);

        $manager = new PasswordResetTokenManager(
            $this->entityManagerForLinkResolve($user),
            new PropertyAccessor(),
            new PasswordResetUserResolver($this->createMock(EntityManagerInterface::class), $registry),
            $registry,
            new NativeClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );

        self::assertSame($user, $manager->resolveUserByLinkToken($plain, 'default'));
    }

    public function testCodeAttemptsLockoutClearsToken(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $user->setPasswordResetToken(hash('sha256', '123456'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::atLeastOnce())->method('persist')->with($user);
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $manager = $this->createManager('code', $entityManager, [
            'password_reset' => ['max_code_attempts' => 2],
        ]);

        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '000000'));
        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '000001'));
        self::assertNull($user->getPasswordResetToken());
        self::assertNull($user->getPasswordResetExpiresAt());
    }

    public function testCodeAlreadyLockedClearsTokenOnNextAttempt(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $user->setPasswordResetToken(hash('sha256', '123456'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter());
        $limiter->hit('seed', 60);
        // Pre-fill attempt key to max by consuming through a manager with max 1 after one wrong try first...
        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['delivery' => 'code', 'max_code_attempts' => 1],
        ]);
        $manager = new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, $registry),
            $registry,
            new NativeClock(),
            $limiter,
        );

        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', 'bad'));
        // Token cleared by lockout on failed attempt; set a new one and hit the "already locked" branch
        $user->setPasswordResetToken(hash('sha256', '999999'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('+1 hour'));
        // After lockout the limiter was reset; hit until max again without clearing via wrong code path:
        $limiter->hit('reset_code:default:' . hash('sha256', 'user@example.com'), 3600);
        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '999999'));
        self::assertNull($user->getPasswordResetToken());
    }

    public function testMatchingCodeThatIsExpiredHitsLimiter(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $user->setPasswordResetToken(hash('sha256', '123456'));
        $user->setPasswordResetExpiresAt(new DateTimeImmutable('-1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $manager = $this->createManager('code', $entityManager);
        self::assertNull($manager->resolveUserByIdentifierAndCode('user@example.com', '123456'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createManager(string $delivery, EntityManagerInterface $entityManager, array $overrides = []): PasswordResetTokenManager
    {
        $registry = $this->profileRegistry($delivery, $overrides);

        return new PasswordResetTokenManager(
            $entityManager,
            new PropertyAccessor(),
            new PasswordResetUserResolver($entityManager, $registry),
            $registry,
            new NativeClock(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function profileRegistry(string $delivery, array $overrides = []): ProfileRegistry
    {
        return ProfileRegistryFactory::single(TestUser::class, array_replace_recursive([
            'password_reset' => [
                'delivery'     => $delivery,
                'token_bytes'  => 32,
                'code_length'  => 6,
                'code_charset' => 'alphanumeric',
            ],
        ], $overrides));
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
