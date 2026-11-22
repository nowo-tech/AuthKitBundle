<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Port;

use Closure;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\Controller\SocialLoginCheckController;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessGuard;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\Port\AuthRequestContexts;
use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\Port\EnrolmentPolicyGate;
use Nowo\AuthKitBundle\Port\Internal\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Port\Ports;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\Ports\PortLog;
use Nowo\AuthKitBundle\Tests\Support\Ports\TwinRulePorts;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Throwable;

use function array_keys;
use function in_array;
use function sprintf;

/**
 * 2.0 (REQ-ARCH-001 rule 3): the 1.x deprecated port interfaces are gone, so the decision of every AuthKit service that
 * asks a host port (MFA requirement, invitations access, account switcher gate and presenter, invitation action
 * policies and grants, social callback selection) must equal the port's own answer for the same user, profile and
 * subject (taken from the 1.46 equivalence matrix, now without the 1.x side). A service that is not a port fails
 * closed (or falls back to AuthKit's built-in where 1.x did), and the built-ins answer as in 1.x.
 */
final class PortMatrixTest extends TestCase
{
    private const IDENTIFIERS = ['alice@example.com', 'bob@example.com', 'mallory@example.com', 'carol@blocked.test'];

    private const ROLES = [[], ['ROLE_ADMIN'], ['ROLE_EDITOR', 'ROLE_ADMIN']];

    private const PROFILES = ['default', 'other'];

    public function testMfaRequirementFollowsThePort(): void
    {
        $port = new TwinRulePorts();
        foreach ([[], ['ROLE_EDITOR']] as $requiredRoles) {
            $registry = ProfileRegistryFactory::single(TestUser::class, ['mfa' => ['enabled' => true, 'issuer' => 'Test', 'required_roles' => $requiredRoles]]);
            foreach ($this->users() as $user) {
                $byRole   = $requiredRoles !== [] && in_array('ROLE_EDITOR', $user->getRoles(), true);
                $expected = $byRole ? ['value' => MfaRequirement::Required] : $this->outcome(static fn (): mixed => $port->requirementFor(AuthUserRefs::fromUser($user), 'default'));
                self::assertSame($expected, $this->outcome(fn (): mixed => $this->manager($registry, $port)->requirement($user)), $user->getUserIdentifier());

                $neither = $this->outcome(fn (): mixed => $this->manager($registry, new stdClass())->requirement($user));
                // Audit NV-L4: a service that is not the port fails closed (a role requirement still wins first).
                self::assertSame($byRole ? ['value' => MfaRequirement::Required] : LogicException::class, $byRole ? $neither : ($neither['class'] ?? null));
            }
        }
    }

    public function testInvitationsAccessFollowsThePort(): void
    {
        $port = new TwinRulePorts();
        foreach ($this->users() as $user) {
            foreach (self::PROFILES as $profile) {
                $tokens = new TokenStorage();
                $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
                $expected = $this->outcome(static fn (): mixed => $port->allowsManage(AuthUserRefs::fromUser($user), $profile));
                self::assertSame($expected, $this->outcome(static fn (): mixed => (new InvitationsAccessGuard(self::locator([$profile => $port]), $tokens))->canManage($profile)));
                self::assertFalse((new InvitationsAccessGuard(self::locator([$profile => new stdClass()]), $tokens))->canManage($profile), 'not a port: refused');
            }
        }
    }

    public function testEnrolmentGateAndPresenterFollowThePortOrTheBuiltIns(): void
    {
        $port    = new TwinRulePorts();
        $profile = ProfileRegistryFactory::single(TestUser::class)->getByName('default');
        foreach ($this->users() as $user) {
            foreach ($this->tokens($user) as $kind => $token) {
                $expected = $this->outcome(static fn (): mixed => $port->allowsEnrolment(EnrolmentPolicyGate::candidate($token)));
                self::assertSame($expected, $this->outcome(fn (): mixed => $this->call($this->vault(self::locator(['default' => $port]), self::locator([])), 'gate', $profile)->isComplete($token)), $kind);
                // Not a port, or no service: the built-in (a user, no pending second factor, no impersonation).
                $builtIn = $kind === 'password';
                self::assertSame($builtIn, $this->call($this->vault(self::locator(['default' => new stdClass()]), self::locator([])), 'gate', $profile)->isComplete($token), 'neither / ' . $kind);
                self::assertSame($builtIn, $this->call($this->vault(self::locator([]), self::locator([])), 'gate', $profile)->isComplete($token), 'none / ' . $kind);
            }
            $presentation = $this->call($this->vault(self::locator([]), self::locator(['default' => $port])), 'presenter', $profile)->present($user);
            self::assertInstanceOf(AccountPresentation::class, $presentation);
            self::assertEquals($port->presentationFor(AuthUserRefs::fromUser($user)), $presentation);
            foreach ([self::locator(['default' => new stdClass()]), self::locator([])] as $presenters) {
                $builtIn = $this->call($this->vault(self::locator([]), $presenters), 'presenter', $profile)->present($user);
                self::assertInstanceOf(AccountPresentation::class, $builtIn);
                self::assertSame([$user->getUserIdentifier(), $user->getUserIdentifier(), null], [$builtIn->name, $builtIn->identifier, $builtIn->avatarUrl]);
            }
        }
    }

    public function testInvitationActionPoliciesFollowThePort(): void
    {
        $port    = new TwinRulePorts();
        $now     = new DateTimeImmutable('2026-11-12 10:00:00');
        $actions = [InvitationActionPolicyInterface::INVITE, InvitationActionPolicyInterface::REVOKE, InvitationActionPolicyInterface::RESEND, InvitationActionPolicyInterface::RETURN_LINK];
        foreach ([null, ...$this->users()] as $actor) {
            foreach (['ok@example.com', 'x@blocked.test'] as $email) {
                foreach ([new InvitationRequest($email), new Invitation('default', $email, null, 7, $now)] as $subject) {
                    foreach ($actions as $action) {
                        $expected = $this->outcome(static fn (): mixed => $port->allows(AuthUserRefs::fromNullableUser($actor), $action, $subject instanceof Invitation ? InvitationSnapshot::of($subject, $now) : $subject));
                        self::assertSame($expected, $this->outcome(fn (): mixed => $this->call($this->invitationManager([$port], $now), 'authorized', $actor, $action, $subject)), sprintf('%s / %s / %s', $actor?->getUserIdentifier() ?? 'anonymous', $subject::class, $action));
                    }
                }
            }
        }

        // A tagged service that is not the port: fail closed.
        $neither = $this->outcome(fn (): mixed => $this->call($this->invitationManager([new stdClass()], $now), 'authorized', null, 'invite', new InvitationRequest('a@example.com')));
        self::assertSame(LogicException::class, $neither['class'] ?? null);
    }

    public function testInvitationGrantsFollowThePort(): void
    {
        $now      = new DateTimeImmutable('2026-11-12 10:00:00');
        $snapshot = InvitationSnapshot::of(new Invitation('default', 'new@example.com', null, 7, $now), $now);
        foreach ($this->users() as $user) {
            $direct   = new PortLog();
            $expected = $this->outcome(static function () use ($direct, $snapshot, $user): mixed {
                (new TwinRulePorts($direct))->grantTo($snapshot, AuthUserRefs::fromUser($user));

                return null;
            });
            $log = new PortLog();
            // InvitationAcceptor grant loop.
            self::assertSame($expected, $this->outcome(static function () use ($log, $snapshot, $user): mixed {
                Ports::invitationGrant(new TwinRulePorts($log))->grant($snapshot, $user);

                return null;
            }));
            self::assertSame($direct->entries, $log->entries);
        }
        $this->expectException(LogicException::class);
        Ports::invitationGrant(new stdClass());
    }

    public function testSocialCallbackSelectionFollowsThePort(): void
    {
        $port    = new TwinRulePorts();
        $profile = ProfileRegistryFactory::single(TestUser::class)->getByName('default');
        foreach ([true, false] as $pending) {
            foreach (['google', 'github'] as $provider) {
                $request = Request::create('/login/social/' . $provider . '/check');
                $session = new Session(new MockArraySessionStorage());
                if ($pending) {
                    $session->set('pending', 1);
                }
                $request->setSession($session);

                $expected = $port->supports(AuthRequestContexts::fromRequest($request, $profile->firewall, $profile->name), $provider);
                self::assertSame($expected, $this->call($this->socialController([$port]), 'handlerFor', $request, $provider, $profile) instanceof SocialCallbackHandlerInterface);
            }
        }
    }

    private function manager(ProfileRegistry $registry, object $policy): MfaManager
    {
        return new MfaManager($registry, new MfaStore($this->createStub(ManagerRegistry::class)), new MfaOwnerResolver(), new MockClock(), new AuthKitAttemptLimiter(new ArrayAdapter()), policies: self::locator(['default' => $policy]));
    }

    /**
     * @return array{class: string, message: string}|array{value: mixed}
     */
    private function outcome(Closure $call): array
    {
        try {
            return ['value' => $call()];
        } catch (Throwable $exception) {
            return ['class' => $exception::class, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return list<TestUser>
     */
    private function users(): array
    {
        $users = [];
        foreach (self::IDENTIFIERS as $identifier) {
            foreach (self::ROLES as $roles) {
                $user = new TestUser($identifier);
                $user->setRoles($roles);
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * @return array<string, TokenInterface>
     */
    private function tokens(TestUser $user): array
    {
        $admin = new TestUser('admin@example.com');
        $admin->setRoles(['ROLE_ADMIN']);

        return [
            'password' => new UsernamePasswordToken($user, 'main', $user->getRoles()),
            'pending'  => new MfaPendingToken(new UsernamePasswordToken($user, 'main', $user->getRoles()), 'main', 'default', 'password', 'challenge', 0),
            'switch'   => new SwitchUserToken($user, 'main', $user->getRoles(), new UsernamePasswordToken($admin, 'main', $admin->getRoles())),
            'null'     => new NullToken(),
        ];
    }

    /**
     * @param array<string, object> $services
     */
    private static function locator(array $services): ContainerInterface
    {
        $factories = [];
        foreach (array_keys($services) as $name) {
            $service          = $services[$name];
            $factories[$name] = static fn (): object => $service;
        }

        return new ServiceLocator($factories);
    }

    private function vault(ContainerInterface $gates, ContainerInterface $presenters): AccountVault
    {
        $vault = (new ReflectionClass(AccountVault::class))->newInstanceWithoutConstructor();
        $class = new ReflectionClass(AccountVault::class);
        $class->getProperty('gates')->setValue($vault, $gates);
        $class->getProperty('presenters')->setValue($vault, $presenters);

        return $vault;
    }

    /**
     * @param list<object> $authorizers
     */
    private function invitationManager(array $authorizers, DateTimeImmutable $now): InvitationManager
    {
        $class   = new ReflectionClass(InvitationManager::class);
        $manager = $class->newInstanceWithoutConstructor();
        $class->getProperty('authorizers')->setValue($manager, $authorizers);
        $class->getProperty('clock')->setValue($manager, new MockClock($now));

        return $manager;
    }

    /**
     * @param list<object> $handlers
     */
    private function socialController(array $handlers): SocialLoginCheckController
    {
        $class      = new ReflectionClass(SocialLoginCheckController::class);
        $controller = $class->newInstanceWithoutConstructor();
        $class->getProperty('callbackHandlers')->setValue($controller, $handlers);
        $class->getProperty('security')->setValue($controller, $this->createStub(Security::class));
        $class->getProperty('loginAuthenticator')->setValue($controller, new ProgrammaticLoginAuthenticator());

        return $controller;
    }

    private function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (fn (): mixed => $this->{$method}(...$arguments))->call($object);
    }
}
