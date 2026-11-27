<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Port\Internal\UserLookupInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\ChildTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\UserInterface;

use function str_repeat;

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

    /**
     * 2.3.0 (audit ZBP-L1, pinned by ZBP-v-I7): a link signs in only under the issuer it was made with, exactly (`!==`):
     * a legacy OAuth2 link (no issuer) is refused for an OpenID Connect profile, and an OIDC link for an OAuth2 profile.
     *
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function issuerMismatches(): iterable
    {
        yield 'legacy link, OIDC profile' => [null, 'https://gitlab.com'];
        yield 'OIDC link, OAuth2 profile' => ['https://gitlab.com', null];
        yield 'OIDC link, another issuer' => ['https://gitlab.com', 'https://git.example.org'];
    }

    #[DataProvider('issuerMismatches')]
    public function testALinkMadeUnderAnotherIssuerIsRefused(?string $linked, ?string $asserted): void
    {
        $link     = (new SocialLoginAccount())->setProvider('gitlab')->setProviderUserId('1234')->setUserClass(TestUser::class)->setUserId('1')->setIssuer($linked);
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn($link);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('find');
        $em->expects(self::never())->method('persist');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('another issuer');
        $this->linker($em, $accounts)->linkOrCreate(
            $this->profile(TestUser::class),
            'gitlab',
            new SocialUserProfile('1234', 'x@example.com', null, [], true, $asserted),
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

    /**
     * REQ-SEC-006 (1.47.0): a provider subject that cannot be told apart from a look-alike is refused before any lookup.
     *
     * @return iterable<string, array{string}>
     */
    public static function unusableSubjects(): iterable
    {
        yield 'trailing space' => ['sub-1 '];
        yield 'leading NBSP' => ["\u{00A0}sub-1"];
        yield 'zero-width space' => ["sub\u{200B}-1"];
        yield 'new line' => ["sub-1\n"];
        yield 'invalid UTF-8' => ["sub-\xC3"];
        yield 'too long' => [str_repeat('s', 256)];
    }

    #[DataProvider('unusableSubjects')]
    public function testAnUnusableProviderSubjectIsRefused(string $subject): void
    {
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->expects(self::never())->method('findOneByProviderSubject');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unusable subject identifier');
        $this->linker($em, $accounts)->linkOrCreate(
            $this->profile(TestUser::class, ['create_user_if_missing' => true]),
            'google',
            new SocialUserProfile($subject, 'new@example.com', null, [], true),
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

    /**
     * Audit QG-M1: with first_user_only, the sign-up of the first account runs again under the user class lock (the
     * first attempt wrote nothing) and its gate is asked again there.
     */
    public function testTheFirstAccountOfAFirstUserOnlyProfileIsCreatedUnderTheClassLock(): void
    {
        $users = $this->createMock(EntityRepository::class);
        $users->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);
        $em->expects(self::exactly(2))->method('persist');

        $user = $this->linker($em, $this->noAccount(), $this->firstUserOnlyGate($em, 0, 0))->linkOrCreate(
            $this->profile(TestUser::class, ['create_user_if_missing' => true], 'first_user_only'),
            'google',
            new SocialUserProfile('sub-5', 'first@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );

        self::assertSame('first@example.com', $user->getUserIdentifier());
    }

    public function testAFirstAccountCreatedMeanwhileRefusesTheSocialSignUpUnderTheClassLock(): void
    {
        $users = $this->createMock(EntityRepository::class);
        $users->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);
        $em->expects(self::never())->method('persist');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('registration is not allowed');
        $this->linker($em, $this->noAccount(), $this->firstUserOnlyGate($em, 0, 1))->linkOrCreate(
            $this->profile(TestUser::class, ['create_user_if_missing' => true], 'first_user_only'),
            'google',
            new SocialUserProfile('sub-6', 'second@example.com', null, [], true),
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

    /** 2.7.0: a credential's own deny wins over the profile's keep, for every caller of the linker. */
    public function testACredentialDenyWinsOverTheProfile(): void
    {
        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findOneByProvider')->willReturn((new SocialLoginCredential())->setProvider('google')->setLinkExistingAccount('deny'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('link_existing_account is deny');

        $this->linkExisting(new TestUser('known@example.com'), ['link_existing_account' => 'keep'], $credentials);
    }

    /** 2.7.0: a credential's own keep over the profile's deny (a provider the application trusts with addresses). */
    public function testACredentialKeepWinsOverTheProfileDeny(): void
    {
        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findOneByProvider')->willReturn((new SocialLoginCredential())->setProvider('google')->setLinkExistingAccount('keep'));
        $existing = new TestUser('known@example.com');
        $existing->setId(5);
        $existing->setPassword('own-hash');

        $this->linkExisting($existing, ['link_existing_account' => 'deny'], $credentials);

        self::assertSame('own-hash', $existing->getPassword());
    }

    /**
     * @param array<string, mixed> $socialLogin
     */
    private function linkExisting(TestUser $existing, array $socialLogin, ?SocialLoginCredentialRepository $credentials = null): UserInterface
    {
        $users = $this->createMock(EntityRepository::class);
        $users->method('findBy')->willReturn([$existing]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);

        return $this->linker($em, $this->noAccount(), null, $credentials)->linkOrCreate(
            $this->profile(TestUser::class, $socialLogin),
            'google',
            new SocialUserProfile('sub-4', 'known@example.com', null, [], true),
            ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null],
        );
    }

    private function firstUserOnlyGate(EntityManagerInterface $em, int $first, int $second): RegistrationGate
    {
        $lookup = $this->createMock(UserLookupInterface::class);
        $lookup->expects(self::exactly(2))->method('countUsers')->willReturnOnConsecutiveCalls($first, $second);

        return new RegistrationGate($em, ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'first_user_only']), $lookup);
    }

    private function noAccount(): SocialLoginAccountRepository
    {
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn(null);

        return $accounts;
    }

    private function linker(EntityManagerInterface $em, SocialLoginAccountRepository $accounts, ?RegistrationGate $gate = null, ?SocialLoginCredentialRepository $credentials = null): SocialAccountLinker
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('random-hash');

        return new SocialAccountLinker($em, $accounts, PropertyAccess::createPropertyAccessor(), $hasher, new MockClock(), registrationGate: $gate, credentials: $credentials);
    }

    /**
     * @param class-string $userClass
     * @param array<string, mixed> $socialLogin
     */
    private function profile(string $userClass, array $socialLogin = [], string $registrationMode = 'first_user_only'): ProfileSettings
    {
        return ProfileRegistryFactory::single($userClass, [
            'social_login'      => ['mode' => 'enabled'] + $socialLogin,
            'registration_mode' => $registrationMode,
        ])->getDefault();
    }
}
