<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Port;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Port\Ports;
use Nowo\AuthKitBundle\Port\QrLoginStepUpGate;
use Nowo\AuthKitBundle\Port\SocialCallbackStepGate;
use Nowo\AuthKitBundle\Port\UserCreatorGate;
use Nowo\AuthKitBundle\Port\UserDirectoryGate;
use Nowo\AuthKitBundle\Port\UserStoreGate;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserCreatorInterface;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\User\UserStoreInterface;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\EnrolmentCandidate;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Unit tests of the 1.46.0 gates that serve the deprecated aliased ports (user lookup / factory / writer, QR step-up)
 * and the social callback step, plus the value objects they return.
 */
final class PortGatesTest extends TestCase
{
    private ProfileSettings $profile;

    protected function setUp(): void
    {
        $this->profile = ProfileRegistryFactory::single(TestUser::class)->getByName('default');
    }

    public function testUserDirectoryGateHandsBackTheSecurityUserOfTheReference(): void
    {
        $user      = new TestUser('ada@example.com');
        $directory = new class($user) implements UserDirectoryInterface {
            public function __construct(private readonly TestUser $user)
            {
            }

            public function findByIdentifier(ProfileSettings $profile, string $identifier): ?AuthUserRef
            {
                return match ($identifier) {
                    'ada@example.com' => AuthUserRef::of($this->user, TestUser::class, '1', 'ada@example.com'),
                    'ghost'           => AuthUserRef::ofId(TestUser::class, '9', 'ghost'),
                    default           => null,
                };
            }

            public function findById(ProfileSettings $profile, string $id): ?AuthUserRef
            {
                return $id === '1' ? AuthUserRef::of($this->user, TestUser::class, '1', 'ada@example.com') : null;
            }

            public function countUsers(ProfileSettings $profile): int
            {
                return 7;
            }

            public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool
            {
                return $property === 'email' && $value === 'ADA@example.com';
            }
        };
        $gate = new UserDirectoryGate($directory);

        self::assertSame($user, $gate->findByIdentifier($this->profile, 'ada@example.com'));
        self::assertNull($gate->findByIdentifier($this->profile, 'nobody'));
        self::assertSame($user, $gate->findById($this->profile, '1'));
        self::assertNull($gate->findById($this->profile, '2'));
        self::assertSame(7, $gate->countUsers($this->profile));
        self::assertTrue($gate->hasCaseVariant($this->profile, 'email', 'ADA@example.com'));
        self::assertFalse($gate->hasCaseVariant($this->profile, 'email', 'x'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('UserDirectoryInterface::findByIdentifier() returned a user reference without a Symfony security user object');
        $gate->findByIdentifier($this->profile, 'ghost');
    }

    public function testUserCreatorGateNeedsAPasswordAuthenticatedUser(): void
    {
        $user    = new TestUser('new@example.com');
        $creator = static fn (?object $made): UserCreatorInterface => new class($made) implements UserCreatorInterface {
            public function __construct(private readonly ?object $made)
            {
            }

            public function create(ProfileSettings $profile, NewUser $newUser): AuthUserRef
            {
                return new AuthUserRef(TestUser::class, null, (string) $newUser->identifier, $newUser->roles, $this->made);
            }
        };
        $newUser = new NewUser(UserCreationSource::Admin, ['ROLE_USER'], identifier: 'new@example.com', plainPassword: 'secret');

        self::assertSame($user, (new UserCreatorGate($creator($user)))->create($this->profile, $newUser));

        try {
            (new UserCreatorGate($creator(new class implements UserInterface {
                public function getRoles(): array
                {
                    return [];
                }

                public function eraseCredentials(): void
                {
                }

                public function getUserIdentifier(): string
                {
                    return 'x';
                }
            })))->create($this->profile, $newUser);
            self::fail('A user without PasswordAuthenticatedUserInterface must be refused.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('does not implement PasswordAuthenticatedUserInterface', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('UserCreatorInterface::create() returned a user reference without a Symfony security user object');
        (new UserCreatorGate($creator(null)))->create($this->profile, $newUser);
    }

    public function testUserStoreGatePassesReferencesCarryingTheUser(): void
    {
        $user = new TestUser('ada@example.com');
        $user->setId(12);
        $store = new class implements UserStoreInterface {
            /** @var list<array{string, AuthUserRef, string|null}> */
            public array $calls = [];

            public function add(ProfileSettings $profile, AuthUserRef $user): void
            {
                $this->calls[] = ['add', $user, null];
            }

            public function remove(ProfileSettings $profile, AuthUserRef $user): void
            {
                $this->calls[] = ['remove', $user, null];
            }

            public function changePassword(ProfileSettings $profile, AuthUserRef $user, string $hashedPassword): void
            {
                $this->calls[] = ['changePassword', $user, $hashedPassword];
            }

            public function save(ProfileSettings $profile, AuthUserRef $user): void
            {
                $this->calls[] = ['save', $user, null];
            }
        };
        $gate = new UserStoreGate($store);
        $gate->add($this->profile, $user);
        $gate->changePassword($this->profile, $user, 'hash');
        $gate->save($this->profile, $user);
        $gate->remove($this->profile, $user);

        self::assertSame(['add', 'changePassword', 'save', 'remove'], array_column($store->calls, 0));
        foreach ($store->calls as [, $ref]) {
            self::assertSame($user, $ref->user());
            self::assertSame('12', $ref->id);
            self::assertSame('ada@example.com', $ref->identifier);
            self::assertSame(TestUser::class, $ref->userClass);
        }
        self::assertSame('hash', $store->calls[1][2]);
    }

    public function testQrLoginStepUpGateRefusesAnythingButTrue(): void
    {
        $policy = new class implements QrLoginStepUpPolicyInterface {
            public ?AuthRequestContext $request = null;

            public ?AuthUserRef $user = null;

            public bool $answer = true;

            public function isUnlocked(AuthRequestContext $request, ?AuthUserRef $user): bool
            {
                $this->request = $request;
                $this->user    = $user;

                return $this->answer;
            }
        };
        $tokens = new TokenStorage();
        $gate   = new QrLoginStepUpGate($policy, ProfileRegistryFactory::requestResolver(TestUser::class), $tokens);
        $gate->assertUnlocked(Request::create('/qr/approve', 'POST'));
        self::assertNull($policy->user);
        self::assertNotNull($policy->request);
        self::assertSame('default', $policy->request->profile);
        self::assertSame('main', $policy->request->firewall);
        self::assertSame('POST', $policy->request->method);

        $user = new TestUser('ada@example.com');
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        $gate->assertUnlocked(Request::create('/qr/approve', 'POST'));
        self::assertSame($user, $this->userOf($policy));

        // Without a token storage (SecurityBundle missing) there is no user.
        (new QrLoginStepUpGate($policy, ProfileRegistryFactory::requestResolver(TestUser::class)))->assertUnlocked(Request::create('/'));
        self::assertNull($policy->user);

        $policy->answer = false;
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('QR login step-up refused.');
        $gate->assertUnlocked(Request::create('/qr/approve', 'POST'));
    }

    public function testSocialCallbackStepGateSignsInAndRedirects(): void
    {
        $user     = new TestUser('ada@example.com');
        $step     = $this->step(SocialCallbackResult::signIn(AuthUserRef::of($user, TestUser::class, '1', 'ada@example.com'), '/welcome', true), SocialCallbackResult::redirect('https://example.com/failed'));
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('login')
            ->with($user, null, 'main', self::callback(static fn (array $badges): bool => $badges[0] instanceof RememberMeBadge && $badges[0]->isEnabled()))
            ->willReturn(null);
        $gate    = new SocialCallbackStepGate($step, $this->profile, $security, new ProgrammaticLoginAuthenticator());
        $request = $this->request(['pending' => 1]);

        self::assertSame($step, $gate->step());
        self::assertTrue($gate->supports($request, 'google'));
        self::assertFalse($gate->supports($request, 'github'));
        self::assertFalse($gate->supports($this->request([]), 'google'));

        $response = $gate->handle($request, $this->context());
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/welcome', $response->getTargetUrl());

        $failure = $gate->handleFailure($request, 'google', SocialCallbackFailure::Failed);
        self::assertInstanceOf(RedirectResponse::class, $failure);
        self::assertSame('https://example.com/failed', $failure->getTargetUrl());
    }

    public function testSocialCallbackStepGateReturnsTheSignInResponseAndKeepsTheDefaultFailure(): void
    {
        $user     = new TestUser('ada@example.com');
        $step     = $this->step(SocialCallbackResult::signIn(AuthUserRef::of($user, TestUser::class, '1', 'ada@example.com'), '/welcome'), null);
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('login')->with($user, null, 'main', [])->willReturn(new Response('mfa challenge'));
        $gate = new SocialCallbackStepGate($step, $this->profile, $security, new ProgrammaticLoginAuthenticator());

        self::assertSame('mfa challenge', $gate->handle($this->request(['pending' => 1]), $this->context())->getContent());
        self::assertNull($gate->handleFailure($this->request([]), 'google', SocialCallbackFailure::Unavailable));
    }

    public function testSocialCallbackStepGateRefusesASignInTheFirewallCannotDo(): void
    {
        $user           = new TestUser('ada@example.com');
        $step           = $this->step(SocialCallbackResult::signIn(AuthUserRef::of($user, TestUser::class, '1', 'ada@example.com'), '/welcome'), null);
        $authenticators = new ProgrammaticLoginAuthenticator(['main' => new ServiceLocator([
            'security.authenticator.custom_a.main' => static fn (): stdClass => new stdClass(),
            'security.authenticator.custom_b.main' => static fn (): stdClass => new stdClass(),
        ])]);
        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('login');
        $gate = new SocialCallbackStepGate($step, $this->profile, $security, $authenticators);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The firewall "main" has no authenticator AuthKit can sign a social user in with.');
        $gate->handle($this->request([]), $this->context());
    }

    public function testSocialCallbackResultOnlyRedirectsToAPathOrAnHttpUrl(): void
    {
        self::assertSame('/next', SocialCallbackResult::redirect('/next')->redirectUrl);
        self::assertSame('http://example.com/', SocialCallbackResult::redirect('http://example.com/')->redirectUrl);
        self::assertNull(SocialCallbackResult::redirect('/next')->signIn);
        self::assertFalse(SocialCallbackResult::redirect('/next')->rememberMe);
        foreach (['//evil.example', 'javascript:alert(1)', 'next', '', '/\\evil.example', "/next\r\nX: y", "https://example.com/\x00", "/a\x7Fb"] as $url) {
            try {
                SocialCallbackResult::redirect($url);
                self::fail($url . ' must be refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('A social callback redirect must be a path or an absolute http(s) URL.', $exception->getMessage());
            }
        }
    }

    public function testEnrolmentCandidateKeepsOnlyStringRolesAndScalarAttributes(): void
    {
        $candidate = new EnrolmentCandidate(null, 'main', true, false, ['ROLE_A', 3, null, 'ROLE_B'], ['a' => 1, 'b' => null, 'c' => ['x'], 4 => 'y', 'd' => new stdClass(), 'e' => 'z']);

        self::assertNull($candidate->user);
        self::assertSame('main', $candidate->firewall);
        self::assertTrue($candidate->secondFactorPending);
        self::assertFalse($candidate->impersonated);
        self::assertSame(['ROLE_A', 'ROLE_B'], $candidate->roleNames);
        self::assertSame(['a' => 1, 'b' => null, 'e' => 'z'], $candidate->attributes);
        self::assertFalse($candidate->rememberMe);
        self::assertSame('', $candidate->tokenClass);
        self::assertFalse($candidate->isFullyAuthenticated(), 'No user.');

        $user = AuthUserRef::ofId(TestUser::class, '1');
        self::assertTrue((new EnrolmentCandidate($user))->isFullyAuthenticated());
        self::assertFalse((new EnrolmentCandidate($user, secondFactorPending: true))->isFullyAuthenticated());
        self::assertFalse((new EnrolmentCandidate($user, rememberMe: true, tokenClass: 'X'))->isFullyAuthenticated());
    }

    public function testPortsKeepTheFallbackForPerProfileServicesAndFailClosedForTaggedOnes(): void
    {
        self::assertNull(Ports::mfaPolicy(new stdClass()));
        self::assertNull(Ports::accountPresenter(null));
        self::assertNull(Ports::enrolmentGate('service'));
        self::assertNull(Ports::invitationsAccessChecker(new stdClass()));

        try {
            Ports::invitationGrant(new stdClass());
            self::fail('A grant implementing neither interface must fail.');
        } catch (LogicException $exception) {
            self::assertSame('The AuthKit tagged service "stdClass" must implement "' . InvitationGrantHandlerInterface::class . '".', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        Ports::invitationActionAuthorizer(new stdClass(), new DateTimeImmutable());
    }

    public function testPortsHandBackDeprecatedImplementationsUnchanged(): void
    {
        $authorizer = $this->createStub(InvitationActionAuthorizerInterface::class);
        $grant      = $this->createStub(InvitationGrantInterface::class);

        self::assertSame($authorizer, Ports::invitationActionAuthorizer($authorizer, new DateTimeImmutable()));
        self::assertSame($grant, Ports::invitationGrant($grant));
    }

    private function userOf(object $policy): ?object
    {
        $ref = (new ReflectionProperty($policy, 'user'))->getValue($policy);

        return $ref instanceof AuthUserRef ? $ref->user() : null;
    }

    private function step(SocialCallbackResult $handled, ?SocialCallbackResult $failed): SocialCallbackStepInterface
    {
        return new class($handled, $failed) implements SocialCallbackStepInterface {
            public function __construct(private readonly SocialCallbackResult $handled, private readonly ?SocialCallbackResult $failed)
            {
            }

            public function supports(AuthRequestContext $request, string $provider): bool
            {
                return $provider === 'google' && $request->sessionHas('pending') && $request->profile === 'default';
            }

            public function handle(AuthRequestContext $request, SocialCallbackContext $context): SocialCallbackResult
            {
                return $this->handled;
            }

            public function handleFailure(AuthRequestContext $request, string $provider, SocialCallbackFailure $reason): ?SocialCallbackResult
            {
                return $this->failed;
            }
        };
    }

    /**
     * @param array<string, mixed> $session
     */
    private function request(array $session): Request
    {
        $request = Request::create('/login/social/google/check');
        $store   = new Session(new MockArraySessionStorage());
        foreach ($session as $name => $value) {
            $store->set($name, $value);
        }
        $request->setSession($store);

        return $request;
    }

    private function context(): SocialCallbackContext
    {
        return new SocialCallbackContext($this->profile, 'google', new SocialUserProfile('sub-1', 'ada@example.com', 'Ada', [], true), ['access_token' => 't', 'refresh_token' => null, 'expires_in' => null, 'raw' => []]);
    }
}
