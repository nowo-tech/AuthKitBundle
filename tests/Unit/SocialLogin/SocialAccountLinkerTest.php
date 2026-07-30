<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\RoleWritableUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class SocialAccountLinkerTest extends TestCase
{
    public function testCreatesUserAndPersistsAccount(): void
    {
        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::exactly(2))->method('flush')->willReturnCallback(
            static function (): void {
                // Assign id after first flush (user create)
            },
        );

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $hasher,
            new MockClock(),
        );

        $profile = ProfileRegistryFactory::single(TestUser::class, [
            'social_login' => ['mode' => 'enabled', 'create_user_if_missing' => true],
        ])->getDefault();

        $user = $linker->linkOrCreate(
            $profile,
            'google',
            new SocialUserProfile('sub-1', 'new@example.com', 'New User', ['sub' => 'sub-1'], true),
            ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 60],
        );

        self::assertInstanceOf(TestUser::class, $user);
        self::assertSame('new@example.com', $user->getEmail());
    }

    public function testLinksExistingUserByEmail(): void
    {
        $existing = new TestUser();
        $existing->setId(3);
        $existing->setEmail('known@example.com');

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $profile = ProfileRegistryFactory::single(TestUser::class)->getDefault();
        $user    = $linker->linkOrCreate(
            $profile,
            'google',
            new SocialUserProfile('sub', 'known@example.com', 'Known', [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => 10],
        );

        self::assertSame($existing, $user);
    }

    public function testReusesExistingSocialAccount(): void
    {
        $existingUser = new TestUser();
        $existingUser->setId(7);
        $existingUser->setEmail('linked@example.com');

        $account = (new SocialLoginAccount())
            ->setProvider('google')
            ->setProviderUserId('sub-1')
            ->setUserClass(TestUser::class)
            ->setUserId('7')
            ->setUserIdentifier('linked@example.com');

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn($account);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(TestUser::class, 7)->willReturn($existingUser);
        $em->expects(self::once())->method('flush');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $profile = ProfileRegistryFactory::single(TestUser::class)->getDefault();
        $user    = $linker->linkOrCreate(
            $profile,
            'google',
            new SocialUserProfile('sub-1', 'linked@example.com', 'Linked', [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );

        self::assertSame($existingUser, $user);
        self::assertSame('a', $account->getAccessToken());
    }

    public function testThrowsWhenLinkedUserClassDoesNotExist(): void
    {
        $account = (new SocialLoginAccount())
            ->setProvider('google')
            ->setProviderUserId('sub-missing-class')
            ->setUserClass('App\\DoesNotExist\\User')
            ->setUserId('1')
            ->setUserIdentifier('ghost@example.com');

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn($account);

        $linker = new SocialAccountLinker(
            $this->createMock(EntityManagerInterface::class),
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $linker->linkOrCreate(
            ProfileRegistryFactory::single('App\\DoesNotExist\\User')->getDefault(),
            'google',
            new SocialUserProfile('sub-missing-class', 'ghost@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    public function testSetsDisplayNameOnCreate(): void
    {
        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::exactly(2))->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $hasher,
            new MockClock(),
        );

        $user = $linker->linkOrCreate(
            ProfileRegistryFactory::single(DisplayNameUser::class)->getDefault(),
            'google',
            new SocialUserProfile('sub', 'dn@example.com', 'Display Me', [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );

        self::assertInstanceOf(DisplayNameUser::class, $user);
        self::assertSame('Display Me', $user->displayName);
    }

    public function testSetsNameWhenDisplayNameNotWritable(): void
    {
        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::exactly(2))->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $hasher,
            new MockClock(),
        );

        $user = $linker->linkOrCreate(
            ProfileRegistryFactory::single(NameOnlyUser::class)->getDefault(),
            'google',
            new SocialUserProfile('sub', 'name@example.com', 'Name Me', [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );

        self::assertInstanceOf(NameOnlyUser::class, $user);
        self::assertSame('Name Me', $user->name);
    }

    public function testSetsRolesViaPropertyWhenNoSetRoles(): void
    {
        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::exactly(2))->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $hasher,
            new MockClock(),
        );

        $user = $linker->linkOrCreate(
            ProfileRegistryFactory::single(RoleWritableUser::class, [
                'registration_role' => 'ROLE_MEMBER',
            ])->getDefault(),
            'google',
            new SocialUserProfile('sub', 'roles@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );

        self::assertInstanceOf(RoleWritableUser::class, $user);
        self::assertContains('ROLE_MEMBER', $user->getRoles());
    }

    public function testResolveUserIdFallsBackToIdentifier(): void
    {
        $existing = new TestUser();
        $existing->setEmail('noid@example.com');
        // id stays null → resolveUserId uses getUserIdentifier()

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $user = $linker->linkOrCreate(
            ProfileRegistryFactory::single(TestUser::class)->getDefault(),
            'google',
            new SocialUserProfile('sub', 'noid@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => 0],
        );

        self::assertSame($existing, $user);
    }

    public function testResolveUserIdViaPropertyAccessor(): void
    {
        $existing        = new StringIdUser();
        $existing->id    = 'uuid-42';
        $existing->email = 'prop@example.com';

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn($existing);

        $persisted = null;
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);
        $em->expects(self::once())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );
        $em->expects(self::once())->method('flush');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $user = $linker->linkOrCreate(
            ProfileRegistryFactory::single(StringIdUser::class)->getDefault(),
            'google',
            new SocialUserProfile('sub', 'prop@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );

        self::assertSame($existing, $user);
        self::assertInstanceOf(SocialLoginAccount::class, $persisted);
        self::assertSame('uuid-42', $persisted->getUserId());
    }

    public function testLoadsLinkedUserWithStringId(): void
    {
        $existingUser        = new StringIdUser();
        $existingUser->id    = 'uuid-1';
        $existingUser->email = 'str@example.com';

        $account = (new SocialLoginAccount())
            ->setProvider('google')
            ->setProviderUserId('sub-1')
            ->setUserClass(StringIdUser::class)
            ->setUserId('uuid-1');

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn($account);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(StringIdUser::class, 'uuid-1')->willReturn($existingUser);
        $em->expects(self::once())->method('flush');

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $user = $linker->linkOrCreate(
            ProfileRegistryFactory::single(StringIdUser::class)->getDefault(),
            'google',
            new SocialUserProfile('sub-1', 'str@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );

        self::assertSame($existingUser, $user);
    }

    public function testCreateUserIfMissingFalseThrows(): void
    {
        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $this->expectException(RuntimeException::class);
        $linker->linkOrCreate(
            ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled', 'create_user_if_missing' => false],
            ])->getDefault(),
            'google',
            new SocialUserProfile('sub', 'x@y.z', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    public function testMissingEmailWhenCreatingThrows(): void
    {
        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $this->expectException(RuntimeException::class);
        $linker->linkOrCreate(
            ProfileRegistryFactory::single(TestUser::class)->getDefault(),
            'google',
            new SocialUserProfile('sub', null, 'N', []),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    public function testMissingLinkedUserThrows(): void
    {
        $account = (new SocialLoginAccount())
            ->setProvider('google')
            ->setProviderUserId('sub')
            ->setUserClass(TestUser::class)
            ->setUserId('404');

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn($account);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $this->expectException(RuntimeException::class);
        $linker->linkOrCreate(
            ProfileRegistryFactory::single(TestUser::class)->getDefault(),
            'google',
            new SocialUserProfile('sub', 'a@b.c', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    public function testRejectsUnverifiedEmailWhenLinkingExistingUser(): void
    {
        $existing = new TestUser();
        $existing->setId(3);
        $existing->setEmail('known@example.com');

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('verified email');

        $linker->linkOrCreate(
            ProfileRegistryFactory::single(TestUser::class)->getDefault(),
            'google',
            new SocialUserProfile('sub', 'known@example.com', 'Known', [], false),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => 10],
        );
    }

    public function testRejectsUnverifiedEmailWhenCreatingUser(): void
    {
        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('verified email');

        $linker->linkOrCreate(
            ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled', 'create_user_if_missing' => true],
            ])->getDefault(),
            'google',
            new SocialUserProfile('sub', 'new@example.com', 'New', [], false),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }
}

final class DisplayNameUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public string $email = '';

    public string $password = '';

    public ?string $displayName = null;

    /** @var list<string> */
    public array $roles = [];

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anon';
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }
}

final class NameOnlyUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public string $email = '';

    public string $password = '';

    public ?string $name = null;

    /** @var list<string> */
    public array $roles = [];

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anon';
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }
}

final class StringIdUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public string $id = '';

    public string $email = '';

    public string $password = '';

    /** @var list<string> */
    public array $roles = [];

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anon';
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }
}
