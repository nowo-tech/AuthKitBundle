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
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessGuard;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\Port\Ports;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\Legacy\Legacy145PortDecisions;
use Nowo\AuthKitBundle\Tests\Support\Ports\BothRulePorts;
use Nowo\AuthKitBundle\Tests\Support\Ports\LegacyRulePorts;
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
use function sprintf;

/**
 * 1.46.0 equivalence matrix (REQ-ARCH-001 rule 3, plan §2.5): every port decision the bundle takes now, through the
 * deprecated interface, through its framework-free twin, through a class implementing both and through a class
 * implementing neither, equals the decision of the LITERAL v1.45.2 code ({@see Legacy145PortDecisions}) with the
 * deprecated interface (same answer, same exception class and message, same fallback). `scripts/mutation-check-port-equivalence.php`
 * mutates the gates and the dispatch and expects this test to fail for every mutation.
 */
final class PortEquivalenceTest extends TestCase
{
    private const IDENTIFIERS = ['alice@example.com', 'bob@example.com', 'mallory@example.com', 'carol@blocked.test'];

    private const ROLES = [[], ['ROLE_ADMIN'], ['ROLE_EDITOR', 'ROLE_ADMIN']];

    private const PROFILES = ['default', 'other'];

    public function testMfaRequirementMatches145(): void
    {
        $cases = 0;
        foreach ([[], ['ROLE_EDITOR']] as $requiredRoles) {
            $registry = ProfileRegistryFactory::single(TestUser::class, ['mfa' => ['enabled' => true, 'issuer' => 'Test', 'required_roles' => $requiredRoles]]);
            $profile  = $registry->getByName('default');
            foreach ($this->users() as $user) {
                $legacy = $this->outcome(static fn (): mixed => Legacy145PortDecisions::mfaRequirement($profile, $user, null, self::locator(['default' => new LegacyRulePorts()])));
                foreach ($this->services() as $kind => $service) {
                    $manager  = new MfaManager($registry, new MfaStore($this->createStub(ManagerRegistry::class)), new MfaOwnerResolver(), new MockClock(), new AuthKitAttemptLimiter(new ArrayAdapter()), policies: self::locator(['default' => $service]));
                    $expected = $kind === 'neither' ? $this->outcome(static fn (): mixed => Legacy145PortDecisions::mfaRequirement($profile, $user, null, self::locator(['default' => $service]))) : $legacy;
                    self::assertSame($expected, $this->outcome(static fn (): mixed => $manager->requirement($user)), sprintf('%s / %s / required %s', $kind, $user->getUserIdentifier(), implode(',', $requiredRoles)));
                    ++$cases;
                }
            }
        }
        self::assertSame(96, $cases);
    }

    public function testInvitationsAccessMatches145(): void
    {
        foreach ($this->users() as $user) {
            foreach (self::PROFILES as $profile) {
                $legacy = $this->outcome(static fn (): mixed => Legacy145PortDecisions::canManage(self::locator([$profile => new LegacyRulePorts()]), $user, $profile));
                foreach ($this->services() as $kind => $service) {
                    $tokens = new TokenStorage();
                    $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
                    $guard    = new InvitationsAccessGuard(self::locator([$profile => $service]), $tokens);
                    $expected = $kind === 'neither' ? $this->outcome(static fn (): mixed => Legacy145PortDecisions::canManage(self::locator([$profile => $service]), $user, $profile)) : $legacy;
                    self::assertSame($expected, $this->outcome(static fn (): mixed => $guard->canManage($profile)), sprintf('%s / %s / %s', $kind, $user->getUserIdentifier(), $profile));
                }
            }
        }
    }

    public function testEnrolmentGateAndPresenterMatch145(): void
    {
        $profile = ProfileRegistryFactory::single(TestUser::class)->getByName('default');
        foreach ($this->users() as $user) {
            foreach ($this->tokens($user) as $tokenKind => $token) {
                $legacyGate = $this->outcome(static fn (): mixed => Legacy145PortDecisions::gate(self::locator(['default' => new LegacyRulePorts()]), $profile)->isComplete($token));
                foreach ($this->services() as $kind => $service) {
                    $vault    = $this->vault(self::locator(['default' => $service]), self::locator(['default' => $service]));
                    $current  = $this->outcome(fn (): mixed => $this->call($vault, 'gate', $profile)->isComplete($token));
                    $expected = $kind === 'neither' ? $this->outcome(static fn (): mixed => Legacy145PortDecisions::gate(self::locator(['default' => $service]), $profile)->isComplete($token)) : $legacyGate;
                    self::assertSame($expected, $current, sprintf('gate %s / %s / %s', $kind, $user->getUserIdentifier(), $tokenKind));
                }
            }

            $legacyPresentation = $this->outcome(static fn (): mixed => Legacy145PortDecisions::presenter(self::locator(['default' => new LegacyRulePorts()]), $profile)->present($user));
            foreach ($this->services() as $kind => $service) {
                $vault    = $this->vault(self::locator([]), self::locator(['default' => $service]));
                $expected = $kind === 'neither' ? $this->outcome(static fn (): mixed => Legacy145PortDecisions::presenter(self::locator(['default' => $service]), $profile)->present($user)) : $legacyPresentation;
                self::assertSame($expected, $this->outcome(fn (): mixed => $this->call($vault, 'presenter', $profile)->present($user)), sprintf('presenter %s / %s', $kind, $user->getUserIdentifier()));
            }
        }

        // No service for the profile: the built-in defaults, as in 1.45.
        $vault = $this->vault(self::locator([]), self::locator([]));
        $user  = new TestUser('alice@example.com');
        self::assertSame($this->outcome(static fn (): mixed => Legacy145PortDecisions::gate(self::locator([]), $profile)->isComplete(new UsernamePasswordToken($user, 'main'))), $this->outcome(fn (): mixed => $this->call($vault, 'gate', $profile)->isComplete(new UsernamePasswordToken($user, 'main'))));
        self::assertSame($this->outcome(static fn (): mixed => Legacy145PortDecisions::presenter(self::locator([]), $profile)->present($user)), $this->outcome(fn (): mixed => $this->call($vault, 'presenter', $profile)->present($user)));
    }

    public function testInvitationActionAuthorizersMatch145(): void
    {
        $now     = new DateTimeImmutable('2026-11-12 10:00:00');
        $actions = [InvitationActionAuthorizerInterface::INVITE, InvitationActionAuthorizerInterface::REVOKE, InvitationActionAuthorizerInterface::RESEND, InvitationActionAuthorizerInterface::RETURN_LINK];
        $actors  = [null, ...$this->users()];
        foreach ($actors as $actor) {
            foreach (['ok@example.com', 'x@blocked.test'] as $email) {
                $subjects = [new InvitationRequest($email), new Invitation('default', $email, null, 7, $now)];
                foreach ($subjects as $subject) {
                    foreach ($actions as $action) {
                        $legacy = $this->outcome(static fn (): mixed => Legacy145PortDecisions::authorized([new LegacyRulePorts()], $actor, $action, $subject));
                        foreach ($this->services() as $kind => $service) {
                            if ($kind === 'neither') {
                                continue;
                            }
                            $manager = $this->invitationManager([$service], $now);
                            self::assertSame($legacy, $this->outcome(fn (): mixed => $this->call($manager, 'authorized', $actor, $action, $subject)), sprintf('%s / %s / %s / %s', $kind, $actor?->getUserIdentifier() ?? 'anonymous', $subject::class, $action));
                        }
                    }
                }
            }
        }

        // A tagged service implementing neither: 1.45 failed with an Error (fail closed), 1.46 with a LogicException.
        $manager = $this->invitationManager([new stdClass()], $now);
        self::assertSame('LogicException', $this->outcome(fn (): mixed => $this->call($manager, 'authorized', null, 'invite', new InvitationRequest('a@example.com')))['class'] ?? null);
        /** @var list<InvitationActionAuthorizerInterface> $neither the 1.45 loop called the method on whatever was tagged */
        $neither = [new stdClass()];
        self::assertSame('Error', $this->outcome(static fn (): mixed => Legacy145PortDecisions::authorized($neither, null, 'invite', new InvitationRequest('a@example.com')))['class'] ?? null);
    }

    public function testInvitationGrantsMatch145(): void
    {
        $now      = new DateTimeImmutable('2026-11-12 10:00:00');
        $snapshot = InvitationSnapshot::of(new Invitation('default', 'new@example.com', null, 7, $now), $now);
        foreach ($this->users() as $user) {
            $legacyLog = new PortLog();
            $legacy    = $this->outcome(static function () use ($legacyLog, $snapshot, $user): mixed {
                Legacy145PortDecisions::grants([new LegacyRulePorts($legacyLog), new LegacyRulePorts($legacyLog)], $snapshot, $user);

                return null;
            });
            foreach (['legacy' => LegacyRulePorts::class, 'twin' => TwinRulePorts::class, 'both' => BothRulePorts::class] as $kind => $class) {
                $log     = new PortLog();
                $grants  = [new $class($log), new LegacyRulePorts($log)];
                $current = $this->outcome(static function () use ($grants, $snapshot, $user): mixed {
                    // InvitationAcceptor 1.46.0 grant loop.
                    foreach ($grants as $grant) {
                        Ports::invitationGrant($grant)->grant($snapshot, $user);
                    }

                    return null;
                });
                self::assertSame($legacy, $current, $kind . ' / ' . $user->getUserIdentifier());
                self::assertSame($legacyLog->entries, $log->entries, $kind . ' / ' . $user->getUserIdentifier());
            }
        }
    }

    public function testSocialCallbackHandlerSelectionMatches145(): void
    {
        $profile = ProfileRegistryFactory::single(TestUser::class)->getByName('default');
        foreach ([true, false] as $pending) {
            foreach (['google', 'github'] as $provider) {
                $request = Request::create('/login/social/' . $provider . '/check');
                $session = new Session(new MockArraySessionStorage());
                if ($pending) {
                    $session->set('pending', 1);
                }
                $request->setSession($session);

                $legacy = Legacy145PortDecisions::handlerFor([new LegacyRulePorts()], $request, $provider) instanceof SocialCallbackHandlerInterface;
                foreach (['legacy' => new LegacyRulePorts(), 'twin' => new TwinRulePorts()] as $kind => $handler) {
                    $controller = $this->socialController([$handler]);
                    self::assertSame($legacy, $this->call($controller, 'handlerFor', $request, $provider, $profile) instanceof SocialCallbackHandlerInterface, sprintf('%s / %s / %s', $kind, $provider, $pending ? 'pending' : 'none'));
                }
            }
        }
    }

    public function testASocialHandlerImplementingNeitherInterfaceFailsClosed(): void
    {
        $profile    = ProfileRegistryFactory::single(TestUser::class)->getByName('default');
        $controller = $this->socialController([new stdClass()]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The AuthKit social callback handler "stdClass" must implement');
        $this->call($controller, 'handlerFor', Request::create('/'), 'google', $profile);
    }

    /**
     * @return array{class: string, message: string}|array{value: mixed}
     */
    private function outcome(Closure $call): array
    {
        try {
            $value = $call();
        } catch (Throwable $exception) {
            return ['class' => $exception::class, 'message' => $exception->getMessage()];
        }

        return ['value' => $value instanceof AccountPresentation ? [$value->name, $value->identifier, $value->avatarUrl] : $value];
    }

    /**
     * @return array<string, object>
     */
    private function services(): array
    {
        return ['legacy' => new LegacyRulePorts(), 'twin' => new TwinRulePorts(), 'both' => new BothRulePorts(), 'neither' => new stdClass()];
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
