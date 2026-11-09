<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\User;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Admin\AdminUserCreator;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginUserResolver;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompletedEvent;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\HostDomainUser;
use Nowo\AuthKitBundle\Tests\Stub\InMemoryHostUsers;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\User\Event\UserRegisteredEvent;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.41 user ports: a host whose security user is not a Doctrine entity (named constructor, no setters) plugs its own
 * factory, writer and lookup; the bundle never calls `new`, PropertyAccessor or the entity manager on that user.
 */
final class HostUserPortsTest extends TestCase
{
    private InMemoryHostUsers $users;

    /** @var list<object> */
    private array $events = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->users      = new InMemoryHostUsers();
        $this->dispatcher = new EventDispatcher();
        foreach ([UserRegisteredEvent::class, PasswordResetCompletedEvent::class] as $event) {
            $this->dispatcher->addListener($event, function (object $e): void {
                $this->events[] = $e;
            });
        }
    }

    public function testRegistrationGoesThroughThePortsAndDispatchesUserRegistered(): void
    {
        $registry  = $this->registry();
        $registrar = $this->registrar($registry);

        self::assertTrue((new RegistrationGate($this->untouchedEntityManager(), $registry, $this->users))->isRegistrationAllowed());

        $user = $registrar->register(['email' => 'first@example.com', 'password' => 'pw']);

        self::assertInstanceOf(HostDomainUser::class, $user);
        self::assertSame('host-hash:pw', $user->getPassword());
        self::assertSame(['add'], $this->users->calls);
        self::assertFalse((new RegistrationGate($this->untouchedEntityManager(), $registry, $this->users))->isRegistrationAllowed());

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertInstanceOf(UserRegisteredEvent::class, $event);
        self::assertSame('default', $event->profile);
        self::assertSame(HostDomainUser::class, $event->userClass);
        self::assertSame('uuid-1', $event->userId);
        self::assertSame('first@example.com', $event->userIdentifier);
        self::assertSame(UserCreationSource::Form, $event->source);
        self::assertSame(['ROLE_USER'], $event->roles);
    }

    public function testFirstUserOnlyClosedAndRaceRollbackUseTheWriter(): void
    {
        $registrar = $this->registrar($this->registry());
        $registrar->register(['email' => 'first@example.com', 'password' => 'pw']);

        try {
            $registrar->register(['email' => 'second@example.com', 'password' => 'pw']);
            self::fail('first_user_only must refuse a second user.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('first_user_only', $exception->getMessage());
        }

        $race              = new InMemoryHostUsers();
        $countingRegistrar = new UserRegistrar(
            $this->registry(),
            $this->untouchedEntityManager(),
            $this->createMock(UserPasswordHasherInterface::class),
            PropertyAccess::createPropertyAccessor(),
            userFactory: $race,
            userWriter: $race,
            userLookup: new class($race) implements UserLookupInterface {
                private int $calls = 0;

                public function __construct(private readonly InMemoryHostUsers $inner)
                {
                }

                public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface
                {
                    return $this->inner->findByIdentifier($profile, $identifier);
                }

                public function findById(ProfileSettings $profile, string $id): ?UserInterface
                {
                    return $this->inner->findById($profile, $id);
                }

                public function countUsers(ProfileSettings $profile): int
                {
                    // First call (gate): nobody yet; second call (after add): a concurrent registration won.
                    return $this->calls++ === 0 ? 0 : 2;
                }

                public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool
                {
                    return false;
                }
            },
            eventDispatcher: $this->dispatcher,
        );

        $this->events = [];

        try {
            $countingRegistrar->register(['email' => 'racer@example.com', 'password' => 'pw']);
            self::fail('The race must roll back.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('race', $exception->getMessage());
        }

        self::assertSame(['add', 'remove'], $race->calls);
        self::assertSame([], $race->users);
        self::assertSame([], $this->events, 'A rolled-back account is not announced.');
    }

    public function testInvitedRegistrationChecksCaseVariantsAndDuplicatesThroughThePorts(): void
    {
        $registry  = $this->registry(['registration_mode' => 'invite_only', 'invitations' => ['mode' => 'enabled']]);
        $registrar = $this->registrar($registry);

        $user = $registrar->registerInvited(['email' => 'Invited@example.com', 'password' => 'pw'], 'default', ['ROLE_EDITOR']);
        self::assertSame(['ROLE_USER', 'ROLE_EDITOR'], $user->getRoles());
        self::assertSame([], $this->events, 'registerInvited() leaves the event to the acceptor (after its commit).');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('any case');
        $registrar->registerInvited(['email' => 'invited@EXAMPLE.com', 'password' => 'pw'], 'default');
    }

    public function testDuplicateFromTheHostWriterBecomesTheRegistrationError(): void
    {
        $registry  = $this->registry(['registration_mode' => 'always']);
        $registrar = $this->registrar($registry);
        $registrar->register(['email' => 'taken@example.com', 'password' => 'pw']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('same unique value');
        $registrar->register(['email' => 'taken@example.com', 'password' => 'pw']);
    }

    public function testAdminCreationUsesThePortsAndDispatchesUserRegistered(): void
    {
        $creator = new AdminUserCreator(
            $this->registry(),
            $this->untouchedEntityManager(),
            $this->createMock(UserPasswordHasherInterface::class),
            PropertyAccess::createPropertyAccessor(),
            $this->dispatcher,
            userFactory: $this->users,
            userWriter: $this->users,
            userLookup: $this->users,
        );

        self::assertFalse($creator->exists('root@example.com'));
        $creator->create('root@example.com', 'secret', ['ROLE_ADMIN']);
        self::assertTrue($creator->exists('root@example.com'));

        $event = $this->events[0] ?? null;
        self::assertInstanceOf(UserRegisteredEvent::class, $event);
        self::assertSame(UserCreationSource::Admin, $event->source);
        self::assertSame(['ROLE_ADMIN'], $event->roles);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin creation failed');
        $creator->create('root@example.com', 'secret', ['ROLE_ADMIN']);
    }

    public function testPasswordResetCompletionUsesTheWriterAndDispatchesTheEvent(): void
    {
        $registry = $this->registry();
        $user     = HostDomainUser::register('uuid-9', 'reset@example.com', 'old', ['ROLE_USER']);

        $tokens = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokens->expects(self::once())->method('clearForUser')->with($user);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('new-hash');

        (new PasswordResetCompleter(
            $this->untouchedEntityManager(),
            $hasher,
            PropertyAccess::createPropertyAccessor(),
            $tokens,
            $registry,
            userWriter: $this->users,
            eventDispatcher: $this->dispatcher,
        ))->complete($user, 'new-password');

        self::assertSame('new-hash', $user->getPassword());
        self::assertSame(['changePassword', 'save'], $this->users->calls);
        self::assertEquals([new PasswordResetCompletedEvent('default', HostDomainUser::class, 'uuid-9', 'reset@example.com')], $this->events);
    }

    public function testResolversUseTheLookup(): void
    {
        $registry = $this->registry();
        $user     = HostDomainUser::register('uuid-3', 'known@example.com', 'h', []);
        $this->users->add($registry->getDefault(), $user);

        $em = $this->untouchedEntityManager();
        self::assertSame($user, (new PasswordResetUserResolver($em, $registry, new UserIdentifierMatcher(), $this->users))->findByIdentifier('known@example.com'));
        self::assertNull((new MagicLoginUserResolver($em, $registry, new UserIdentifierMatcher(), $this->users))->findByIdentifier('nobody@example.com', 'default'));
    }

    public function testSocialSignUpAndSignInGoThroughThePorts(): void
    {
        $registry = $this->registry(['registration_mode' => 'always', 'social_login' => ['mode' => 'enabled', 'create_user_if_missing' => true]]);
        $profile  = $registry->getDefault();

        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn(null);

        // The bundle's own social account row still goes through Doctrine; the host user never does.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(SocialLoginAccount::class));
        $em->expects(self::once())->method('flush');
        $em->expects(self::never())->method('getRepository');

        $linker = $this->linker($em, $accounts, $registry);
        $user   = $linker->linkOrCreate($profile, 'google', new SocialUserProfile('sub-1', 'social@example.com', 'Social', [], true), ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null]);

        self::assertInstanceOf(HostDomainUser::class, $user);
        self::assertSame(['add'], $this->users->calls);
        $event = $this->events[0] ?? null;
        self::assertInstanceOf(UserRegisteredEvent::class, $event);
        self::assertSame(UserCreationSource::Social, $event->source);
        self::assertSame('uuid-1', $event->userId);
    }

    public function testSocialSignInLoadsTheLinkedUserByIdAndAnnouncesNothing(): void
    {
        $registry = $this->registry();
        $profile  = $registry->getDefault();
        $user     = HostDomainUser::register('uuid-5', 'linked@example.com', 'h', []);
        $this->users->add($profile, $user);

        $account  = (new SocialLoginAccount())->setProvider('google')->setProviderUserId('sub-5')->setUserClass(HostDomainUser::class)->setUserId('uuid-5')->setUserIdentifier('linked@example.com');
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn($account);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('find');

        $signedIn = null;
        $this->linker($this->transactionalEntityManager($em), $accounts, $registry)->linkAndSignIn(
            $profile,
            'google',
            new SocialUserProfile('sub-5', 'linked@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
            static function (UserInterface $u) use (&$signedIn): void {
                $signedIn = $u;
            },
        );

        self::assertSame($user, $signedIn);
        self::assertSame([], $this->events);

        $missing = (new SocialLoginAccount())->setProvider('google')->setProviderUserId('sub-6')->setUserClass(HostDomainUser::class)->setUserId('uuid-404')->setUserIdentifier('gone@example.com');
        $gone    = $this->createMock(SocialLoginAccountRepository::class);
        $gone->method('findOneByProviderSubject')->willReturn($missing);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');
        $this->linker($this->createMock(EntityManagerInterface::class), $gone, $registry)->linkOrCreate($profile, 'google', new SocialUserProfile('sub-6', null, null, [], true), ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null]);
    }

    public function testLinkAndSignInDispatchesUserRegisteredAfterTheCommit(): void
    {
        $registry = $this->registry(['registration_mode' => 'always', 'social_login' => ['mode' => 'enabled', 'create_user_if_missing' => true]]);
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn(null);

        $committed = false;
        $em        = $this->transactionalEntityManager($this->createMock(EntityManagerInterface::class), static function () use (&$committed): void {
            $committed = true;
        });
        $this->dispatcher->addListener(UserRegisteredEvent::class, static function () use (&$committed): void {
            self::assertTrue($committed, 'UserRegisteredEvent must follow the commit.');
        });

        $this->linker($em, $accounts, $registry)->linkAndSignIn(
            $registry->getDefault(),
            'google',
            new SocialUserProfile('sub-7', 'fresh@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
            static fn (UserInterface $u): null => null,
        );

        self::assertCount(1, $this->events);
    }

    public function testLinkingAnExistingAccountReplacesThePasswordThroughTheWriter(): void
    {
        $registry = $this->registry();
        $profile  = $registry->getDefault();
        $user     = HostDomainUser::register('uuid-8', 'existing@example.com', 'attacker-chosen', []);
        $this->users->add($profile, $user);
        $this->users->calls = [];

        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn(null);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('random-hash');

        $linked = $this->linker($this->createMock(EntityManagerInterface::class), $accounts, $registry, $hasher)
            ->linkOrCreate($profile, 'google', new SocialUserProfile('sub-8', 'existing@example.com', null, [], true), ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null]);

        self::assertSame($user, $linked);
        self::assertSame('random-hash', $user->getPassword());
        self::assertSame(['changePassword'], $this->users->calls);
        self::assertSame([], $this->events);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function registry(array $overrides = []): ProfileRegistry
    {
        return ProfileRegistryFactory::single(HostDomainUser::class, $overrides);
    }

    private function registrar(ProfileRegistry $registry): UserRegistrar
    {
        return new UserRegistrar(
            $registry,
            $this->untouchedEntityManager(),
            $this->createMock(UserPasswordHasherInterface::class),
            PropertyAccess::createPropertyAccessor(),
            userFactory: $this->users,
            userWriter: $this->users,
            userLookup: $this->users,
            eventDispatcher: $this->dispatcher,
        );
    }

    private function linker(EntityManagerInterface $em, SocialLoginAccountRepository $accounts, ProfileRegistry $registry, ?UserPasswordHasherInterface $hasher = null): SocialAccountLinker
    {
        return new SocialAccountLinker(
            $em,
            $accounts,
            PropertyAccess::createPropertyAccessor(),
            $hasher ?? $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
            registrationGate: new RegistrationGate($em, $registry, $this->users),
            userLookup: $this->users,
            userFactory: $this->users,
            userWriter: $this->users,
            eventDispatcher: $this->dispatcher,
        );
    }

    /** An entity manager the host-user flows must not touch. */
    private function untouchedEntityManager(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        foreach (['persist', 'remove', 'flush', 'getRepository', 'find', 'createQueryBuilder', 'getClassMetadata'] as $method) {
            $em->expects(self::never())->method($method);
        }

        return $em;
    }

    private function transactionalEntityManager(EntityManagerInterface&MockObject $em, ?Closure $onCommit = null): EntityManagerInterface
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getDummySelectSQL')->willReturn('SELECT 1');
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeQuery')->willReturn($this->createMock(Result::class));
        $connection->expects(self::once())->method('commit')->willReturnCallback(static function () use ($onCommit): void {
            if ($onCommit instanceof Closure) {
                $onCommit();
            }
        });
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }
}
