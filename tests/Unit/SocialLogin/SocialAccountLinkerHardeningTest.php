<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\ChildTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * SEC-004 regressions of the social login linker: H1 (look-alike e-mail), H2 (cross-profile account), M1
 * (registration_mode), M2 (pre-registered account).
 */
final class SocialAccountLinkerHardeningTest extends TestCase
{
    /**
     * H2: a member's social identity must not sign in to the admin profile as the admin with the same integer id.
     */
    public function testASubjectLinkedForAnotherUserClassNeverLoadsThisProfileUser(): void
    {
        $memberLink = (new SocialLoginAccount())
            ->setProvider('google')
            ->setProviderUserId('sub-1')
            ->setUserClass(TestUser::class)
            ->setUserId('1')
            ->setUserIdentifier('member@example.com');

        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->expects(self::once())->method('findOneByProviderSubject')
            ->with('google', 'sub-1', ChildTestUser::class)
            ->willReturnCallback(static fn (string $p, string $s, ?string $class): ?SocialLoginAccount => $class === TestUser::class ? $memberLink : null);

        $users = $this->createMock(EntityRepository::class);
        $users->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);
        $em->expects(self::never())->method('find');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('create_user_if_missing is false');

        $this->linker($em, $accounts)->linkOrCreate(
            $this->profile(ChildTestUser::class, ['create_user_if_missing' => false]),
            'google',
            new SocialUserProfile('sub-1', 'member@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    /** H2: a stored link whose user row is of another class is refused even when the id exists. */
    public function testALinkedRowOfAnotherClassIsRefused(): void
    {
        $link     = (new SocialLoginAccount())->setProvider('google')->setProviderUserId('sub-1')->setUserClass(ChildTestUser::class)->setUserId('1');
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn($link);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(new TestUser('admin@example.com'));

        $this->expectException(RuntimeException::class);
        $this->linker($em, $accounts)->linkOrCreate(
            $this->profile(ChildTestUser::class),
            'google',
            new SocialUserProfile('sub-1', 'x@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    /** H1: an IdP-verified look-alike address never links to the account it collates with. */
    public function testAVerifiedLookAlikeEmailDoesNotLinkToTheVictim(): void
    {
        $victim = new TestUser('victim@example.com');
        $users  = $this->createMock(EntityRepository::class);
        $users->method('findBy')->willReturn([$victim]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);
        $em->expects(self::never())->method('persist');

        $this->expectException(RuntimeException::class);
        $this->linker($em, $this->noAccount())->linkOrCreate(
            $this->profile(TestUser::class, ['create_user_if_missing' => false]),
            'google',
            new SocialUserProfile('sub-2', "victim@ex\u{00E1}mple.com", null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    /** M1: social sign-up follows registration_mode. */
    public function testNoUserIsCreatedWhenRegistrationIsNotAllowed(): void
    {
        $users = $this->createMock(EntityRepository::class);
        $users->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);
        $em->expects(self::never())->method('persist');

        $gate = new RegistrationGate($em, ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'disabled']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('registration is not allowed');
        $this->linker($em, $this->noAccount(), $gate)->linkOrCreate(
            $this->profile(TestUser::class, ['create_user_if_missing' => true]),
            'google',
            new SocialUserProfile('sub-3', 'new@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    /** M2: linking to a pre-registered account replaces its password and pending reset credential. */
    public function testLinkingAnExistingAccountResetsItsCredentialsByDefault(): void
    {
        $existing = new TestUser('known@example.com');
        $existing->setId(4);
        $existing->setPassword('attacker-chosen-hash');
        $existing->setPasswordResetToken('pending');

        $linked = $this->linkExisting($existing, []);

        self::assertSame($existing, $linked);
        self::assertSame('random-hash', $existing->getPassword());
        self::assertNull($existing->getPasswordResetToken());
    }

    public function testLinkingAnExistingAccountCanBeDenied(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('link_existing_account is deny');

        $this->linkExisting(new TestUser('known@example.com'), ['link_existing_account' => 'deny']);
    }

    public function testKeepLinksWithoutTouchingThePassword(): void
    {
        $existing = new TestUser('known@example.com');
        $existing->setId(5);
        $existing->setPassword('own-hash');

        $this->linkExisting($existing, ['link_existing_account' => 'keep']);

        self::assertSame('own-hash', $existing->getPassword());
    }

    /**
     * @param array<string, mixed> $socialLogin
     */
    private function linkExisting(TestUser $existing, array $socialLogin): UserInterface
    {
        $users = $this->createMock(EntityRepository::class);
        $users->method('findBy')->willReturn([$existing]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);

        return $this->linker($em, $this->noAccount())->linkOrCreate(
            $this->profile(TestUser::class, $socialLogin),
            'google',
            new SocialUserProfile('sub-4', 'known@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    private function noAccount(): SocialLoginAccountRepository
    {
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn(null);

        return $accounts;
    }

    private function linker(EntityManagerInterface $em, SocialLoginAccountRepository $accounts, ?RegistrationGate $gate = null): SocialAccountLinker
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('random-hash');

        return new SocialAccountLinker($em, $accounts, PropertyAccess::createPropertyAccessor(), $hasher, new MockClock(), registrationGate: $gate);
    }

    /**
     * @param class-string $userClass
     * @param array<string, mixed> $socialLogin
     */
    private function profile(string $userClass, array $socialLogin = []): ProfileSettings
    {
        return ProfileRegistryFactory::single($userClass, [
            'social_login' => ['mode' => 'enabled'] + $socialLogin,
        ])->getDefault();
    }
}
