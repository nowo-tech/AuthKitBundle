<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\User;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Admin\AdminUserCreator;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Identity\LowercaseAsciiEmailIdentifierNormalizer;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\HostDomainUser;
use Nowo\AuthKitBundle\Tests\Stub\InMemoryHostUsers;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\User\NewUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\User\UserInterface;

use function md5;

/**
 * Audit OV (1.49.0), every path that creates a user, with a host user whose identifier cannot be written after
 * construction ({@see HostDomainUser}, DDD host):
 *
 * - OV-L1: the factory / host creator receives the NORMALISED identifier in {@see NewUser};
 * - OV-M1: with a normaliser that changes the value, an identifier whose lower-cased form is already stored is refused
 *   (pre-hijack of a legacy `Bob@x` on an exact column by registering `bob@x`);
 * - OV-L4: an identifier the normaliser refuses is never stored.
 *
 * With the default normaliser the 1.48.0 byte-exact behaviour stays: case variants are distinct accounts.
 */
final class NewUserIdentifierGuardTest extends TestCase
{
    private InMemoryHostUsers $users;

    protected function setUp(): void
    {
        $this->users = new InMemoryHostUsers();
    }

    public function testRegistrationGivesTheFactoryTheNormalisedIdentifier(): void
    {
        $user = $this->registrar(true)->register(['email' => 'Bob@Example.COM', 'password' => 'pw']);

        self::assertSame('bob@example.com', $user->getUserIdentifier());
    }

    public function testInvitedRegistrationGivesTheFactoryTheNormalisedIdentifier(): void
    {
        $user = $this->registrar(true, ['registration_mode' => 'invite_only', 'invitations' => ['mode' => 'enabled']])
            ->registerInvited(['email' => 'Invited@Example.COM', 'password' => 'pw'], 'default');

        self::assertSame('invited@example.com', $user->getUserIdentifier());
    }

    public function testAdminCreationGivesTheFactoryTheNormalisedIdentifier(): void
    {
        self::assertSame('root@example.com', $this->adminCreator(true)->create('Root@Example.COM', 'pw', ['ROLE_ADMIN'])->getUserIdentifier());
    }

    public function testSocialSignUpGivesTheFactoryTheNormalisedIdentifier(): void
    {
        self::assertSame('social@example.com', $this->socialSignUp(true, 'Social@Example.COM')->getUserIdentifier());
    }

    /** Probe P1d: registering `BOB@Example.com` next to the legacy `Bob@example.com` would capture its lookups. */
    public function testRegistrationNextToALegacyCaseVariantIsRefused(): void
    {
        $this->storeLegacy('Bob@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Registration failed: a "' . HostDomainUser::class . '" with the same identifier (any case) already exists.');
        $this->registrar(true, ['registration_mode' => 'always'])->register(['email' => 'BOB@Example.com', 'password' => 'pw']);
    }

    public function testAdminCreationNextToALegacyCaseVariantIsRefused(): void
    {
        $this->storeLegacy('Bob@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin creation failed: a "' . HostDomainUser::class . '" with the same identifier (any case) already exists.');
        $this->adminCreator(true)->create('bob@example.com', 'pw', ['ROLE_ADMIN']);
    }

    public function testSocialSignUpNextToALegacyCaseVariantIsRefused(): void
    {
        $this->storeLegacy('Bob@example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('with the same identifier (any case) already exists');
        $this->socialSignUp(true, 'BOB@example.com');
    }

    public function testTheDefaultNormaliserKeepsCaseVariantsDistinct(): void
    {
        $this->storeLegacy('Bob@example.com');

        $user = $this->registrar(false, ['registration_mode' => 'always'])->register(['email' => 'bob@example.com', 'password' => 'pw']);
        self::assertSame('bob@example.com', $user->getUserIdentifier());
        self::assertSame('BOB@example.com', $this->adminCreator(false)->create('BOB@example.com', 'pw', [])->getUserIdentifier());
        self::assertSame('Social@Example.COM', $this->socialSignUp(false, 'Social@Example.COM')->getUserIdentifier());
    }

    public function testRegistrationRefusesAnIdentifierTheNormaliserRefuses(): void
    {
        foreach ([false, true] as $lower) {
            try {
                $this->registrar($lower, ['registration_mode' => 'always'])->register(['email' => "ann\u{00AD}e@example.com", 'password' => 'pw']);
                self::fail('A refused identifier must not be stored.');
            } catch (RuntimeException $exception) {
                self::assertSame('Registration failed: the identifier is not usable (refused by the identifier normaliser).', $exception->getMessage());
            }
        }
        self::assertSame([], $this->users->users);
    }

    public function testAdminCreationRefusesAnIdentifierTheNormaliserRefuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin creation failed: the identifier is not usable');
        $this->adminCreator(false)->create('root@example.com ', 'pw', ['ROLE_ADMIN']);
    }

    public function testSocialSignUpRefusesAnIdentifierTheNormaliserRefuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the identifier is not usable');
        $this->socialSignUp(false, "x\u{200D}y@example.com");
    }

    private function storeLegacy(string $identifier): void
    {
        $profile = $this->registry()->getDefault();
        $this->users->add($profile, HostDomainUser::register('legacy', $identifier, 'legacy-hash', []));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function registry(array $overrides = []): ProfileRegistry
    {
        return ProfileRegistryFactory::single(HostDomainUser::class, $overrides);
    }

    private function matcher(bool $lower): UserIdentifierMatcher
    {
        return new UserIdentifierMatcher(null, $lower ? new LowercaseAsciiEmailIdentifierNormalizer() : null);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function registrar(bool $lower, array $overrides = []): UserRegistrar
    {
        return new UserRegistrar(
            $this->registry($overrides),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserPasswordHasherInterface::class),
            PropertyAccess::createPropertyAccessor(),
            userFactory: $this->users,
            userWriter: $this->users,
            userLookup: $this->users,
            identifierMatcher: $this->matcher($lower),
        );
    }

    private function adminCreator(bool $lower): AdminUserCreator
    {
        return new AdminUserCreator(
            $this->registry(),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserPasswordHasherInterface::class),
            PropertyAccess::createPropertyAccessor(),
            userFactory: $this->users,
            userWriter: $this->users,
            userLookup: $this->users,
            identifierMatcher: $this->matcher($lower),
        );
    }

    private function socialSignUp(bool $lower, string $email): UserInterface
    {
        $registry = $this->registry(['registration_mode' => 'always', 'social_login' => ['mode' => 'enabled', 'create_user_if_missing' => true]]);
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('findOneByProviderSubject')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->with(self::isInstanceOf(SocialLoginAccount::class));

        $linker = new SocialAccountLinker(
            $em,
            $accounts,
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
            registrationGate: new RegistrationGate($em, $registry, $this->users),
            identifierMatcher: $this->matcher($lower),
            userLookup: $this->users,
            userFactory: $this->users,
            userWriter: $this->users,
        );

        return $linker->linkOrCreate($registry->getDefault(), 'google', new SocialUserProfile('sub-' . md5($email), $email, null, [], true), ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null]);
    }
}
