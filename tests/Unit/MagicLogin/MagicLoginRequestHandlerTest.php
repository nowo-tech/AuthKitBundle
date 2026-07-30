<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\MagicLogin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\MagicLogin\LoggingMagicLoginNotifier;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginRequestedEvent;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginRequestHandler;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginUserResolver;
use Nowo\AuthKitBundle\MagicLogin\NullMagicLoginNotifier;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_key_exists;

final class MagicLoginRequestHandlerTest extends TestCase
{
    public function testDoesNothingWhenUserMissing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::never())->method('notify');

        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->expects(self::never())->method('createLoginLink');

        $this->handler(
            new MagicLoginUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            $notifier,
            $loginLinkHandler,
        )->handle('missing@example.com');
    }

    public function testNotifiesWhenUserExists(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $expires = new DateTimeImmutable('+10 minutes');
        $details = new LoginLinkDetails('https://example.test/magic-login/check?user=1', $expires);

        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->expects(self::once())
            ->method('createLoginLink')
            ->with(self::identicalTo($user), self::isInstanceOf(Request::class), 600)
            ->willReturn($details);

        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify')->with(self::callback(
            static function (MagicLoginNotificationContext $context) use ($expires): bool {
                return $context->loginUrl === 'https://example.test/magic-login/check?user=1'
                    && $context->identifier === 'user@example.com'
                    && $context->maskedIdentifier === 'u***@example.com'
                    && $context->expiresAt == $expires;
            },
        ));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::isInstanceOf(MagicLoginRequestedEvent::class));

        $stack = new RequestStack();
        $stack->push(Request::create('/magic-login'));

        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled', 'lifetime' => 600, 'max_uses' => 1],
        ]);

        $handler = new MagicLoginRequestHandler(
            new MagicLoginUserResolver($entityManager, $registry),
            $notifier,
            $dispatcher,
            $registry,
            $stack,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            $loginLinkHandler,
        );

        $handler->handle('user@example.com');
    }

    public function testSkipsSilentlyWhenLoginLinkHandlerMissing(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $handler = new MagicLoginRequestHandler(
            new MagicLoginUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            new NullMagicLoginNotifier(),
            $this->createMock(EventDispatcherInterface::class),
            ProfileRegistryFactory::single(TestUser::class),
            new RequestStack(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );
        $handler->handle('user@example.com');
        $this->addToAssertionCount(1);
    }

    public function testHandleStopsWhenRateLimited(): void
    {
        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->expects(self::never())->method('createLoginLink');

        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled', 'request_rate_limit' => 1],
        ]);

        $handler = new MagicLoginRequestHandler(
            new MagicLoginUserResolver($this->createMock(EntityManagerInterface::class), $registry),
            new NullMagicLoginNotifier(),
            $this->createMock(EventDispatcherInterface::class),
            $registry,
            new RequestStack(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            $loginLinkHandler,
        );

        $handler->handle('a@b.c');
        $handler->handle('a@b.c');
        $this->addToAssertionCount(1);
    }

    public function testIgnoresNonUserInterface(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(new stdClass());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->expects(self::never())->method('createLoginLink');

        $this->handler(
            new MagicLoginUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            new NullMagicLoginNotifier(),
            $loginLinkHandler,
        )->handle('x');
    }

    public function testLoggingNotifierLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Magic login link requested',
            self::callback(static function (array $context): bool {
                return !array_key_exists('login_url', $context)
                    && ($context['action'] ?? null) === 'magic_login_notify';
            }),
        );

        (new LoggingMagicLoginNotifier($logger))->notify(new MagicLoginNotificationContext(
            'a@b.c',
            'https://example.test/link',
            new DateTimeImmutable('+5 minutes'),
            'a***@b.c',
        ));
    }

    public function testNullNotifierIsNoop(): void
    {
        $this->expectNotToPerformAssertions();

        (new NullMagicLoginNotifier())->notify(new MagicLoginNotificationContext(
            'a@b.c',
            'https://example.test/link',
            new DateTimeImmutable('+5 minutes'),
        ));
    }

    public function testRequestedEventExposesContext(): void
    {
        $context = new MagicLoginNotificationContext(
            'a@b.c',
            'https://example.test/link',
            new DateTimeImmutable('+5 minutes'),
        );
        self::assertSame($context, (new MagicLoginRequestedEvent($context))->getContext());
    }

    public function testMasksShortIdentifierWithoutAt(): void
    {
        $user = new TestUser();
        $user->setEmail('ab');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $details          = new LoginLinkDetails('https://example.test/l', new DateTimeImmutable('+1 minute'));
        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->method('createLoginLink')->willReturn($details);

        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify')->with(self::callback(
            static fn (MagicLoginNotificationContext $c): bool => $c->maskedIdentifier === '***',
        ));

        $this->handler(
            new MagicLoginUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            $notifier,
            $loginLinkHandler,
        )->handle('ab');
    }

    public function testMasksLongIdentifierWithoutAt(): void
    {
        $user = new class implements UserInterface {
            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'username';
            }
        };

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $details          = new LoginLinkDetails('https://example.test/l', new DateTimeImmutable('+1 minute'));
        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->method('createLoginLink')->willReturn($details);

        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify')->with(self::callback(
            static fn (MagicLoginNotificationContext $c): bool => $c->maskedIdentifier === 'u******e',
        ));

        $this->handler(
            new MagicLoginUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            $notifier,
            $loginLinkHandler,
        )->handle('username');
    }

    public function testHandleWithNamedProfile(): void
    {
        $user = new TestUser();
        $user->setEmail('a@b.c');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $details          = new LoginLinkDetails('https://example.test/l', new DateTimeImmutable('+1 minute'));
        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->method('createLoginLink')->willReturn($details);

        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify');

        $this->handler(
            new MagicLoginUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            $notifier,
            $loginLinkHandler,
        )->handle('a@b.c', 'default');
    }

    public function testCreateLoginLinkWithoutCurrentRequest(): void
    {
        $user = new TestUser();
        $user->setEmail('a@b.c');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $details          = new LoginLinkDetails('https://example.test/l', new DateTimeImmutable('+1 minute'));
        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->expects(self::once())
            ->method('createLoginLink')
            ->with(self::anything(), null, 600)
            ->willReturn($details);

        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify');

        $this->handler(
            new MagicLoginUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            $notifier,
            $loginLinkHandler,
        )->handle('a@b.c');
    }

    public function testPassesNullLifetimeWhenNotInteger(): void
    {
        $user = new TestUser();
        $user->setEmail('a@b.c');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $details          = new LoginLinkDetails('https://example.test/l', new DateTimeImmutable('+1 minute'));
        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->expects(self::once())
            ->method('createLoginLink')
            ->with(self::anything(), null, null)
            ->willReturn($details);

        $notifier = $this->createMock(MagicLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify');

        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled', 'lifetime' => '600', 'max_uses' => 1],
        ]);

        $handler = new MagicLoginRequestHandler(
            new MagicLoginUserResolver($entityManager, $registry),
            $notifier,
            $this->createMock(EventDispatcherInterface::class),
            $registry,
            new RequestStack(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            $loginLinkHandler,
        );

        $handler->handle('a@b.c');
    }

    private function handler(
        MagicLoginUserResolver $resolver,
        MagicLoginNotifierInterface $notifier,
        LoginLinkHandlerInterface $loginLinkHandler,
    ): MagicLoginRequestHandler {
        return new MagicLoginRequestHandler(
            $resolver,
            $notifier,
            $this->createMock(EventDispatcherInterface::class),
            ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'enabled', 'lifetime' => 600, 'max_uses' => 1],
            ]),
            new RequestStack(),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            $loginLinkHandler,
        );
    }
}
