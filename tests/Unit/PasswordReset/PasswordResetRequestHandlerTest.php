<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\NullPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestedEvent;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class PasswordResetRequestHandlerTest extends TestCase
{
    public function testHandleDoesNothingWhenUserMissing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->expects(self::never())->method('createForUser');

        $handler = $this->handler(
            new PasswordResetUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            $tokenManager,
            'link',
        );
        $handler->handle('missing@example.com');
    }

    public function testHandleCreatesTokenAndNotifiesForLinkDelivery(): void
    {
        $user   = new TestUser();
        $result = new PasswordResetTokenResult(
            $user,
            'token',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Link,
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn($result);

        $notifier = new class implements PasswordResetNotifierInterface {
            public bool $called = false;

            public function notify(
                PasswordResetTokenResult $token,
                PasswordResetNotificationContext $context,
            ): void {
                $this->called = true;
            }
        };

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('https://example.test/reset');
        $urlGenerator = AuthKitTestUrlGenerator::fromMock($inner);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::isInstanceOf(PasswordResetRequestedEvent::class));

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['delivery' => 'link'],
            ])),
            $tokenManager,
            $notifier,
            $urlGenerator,
            $dispatcher,
            ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['delivery' => 'link'],
            ]),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        $handler->handle('user@example.com');

        self::assertTrue($notifier->called);
    }

    public function testHandleMasksIdentifier(): void
    {
        $user   = new TestUser();
        $result = new PasswordResetTokenResult(
            $user,
            '123456',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Code,
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn($result);

        $notifier = new class implements PasswordResetNotifierInterface {
            public ?PasswordResetNotificationContext $context = null;

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                $this->context = $context;
            }
        };

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['delivery' => 'code'],
            ])),
            $tokenManager,
            $notifier,
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['delivery' => 'code'],
            ]),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        $handler->handle('user@example.com');

        self::assertSame('u***@example.com', $notifier->context?->maskedIdentifier);
    }

    public function testHandleMasksShortUsername(): void
    {
        $user   = new TestUser();
        $result = new PasswordResetTokenResult(
            $user,
            '123456',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Code,
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn($result);

        $notifier = new class implements PasswordResetNotifierInterface {
            public ?PasswordResetNotificationContext $context = null;

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                $this->context = $context;
            }
        };

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['delivery' => 'code'],
            ])),
            $tokenManager,
            $notifier,
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['delivery' => 'code'],
            ]),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        $handler->handle('ab');

        self::assertSame('***', $notifier->context?->maskedIdentifier);
    }

    public function testHandleMasksLongUsername(): void
    {
        $user   = new TestUser();
        $result = new PasswordResetTokenResult(
            $user,
            '123456',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Code,
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn($result);

        $notifier = new class implements PasswordResetNotifierInterface {
            public ?PasswordResetNotificationContext $context = null;

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                $this->context = $context;
            }
        };

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['delivery' => 'code'],
            ])),
            $tokenManager,
            $notifier,
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            ProfileRegistryFactory::single(TestUser::class, [
                'password_reset' => ['delivery' => 'code'],
            ]),
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        $handler->handle('username');

        self::assertSame('u******e', $notifier->context?->maskedIdentifier);
    }

    public function testHandleStopsWhenRateLimited(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->expects(self::never())->method('createForUser');

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => [
                'delivery'           => 'link',
                'request_rate_limit' => 1,
            ],
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $profileRegistry),
            $tokenManager,
            new NullPasswordResetNotifier(),
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            $profileRegistry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        $handler->handle('a@b.c');
        $handler->handle('a@b.c');
        $this->addToAssertionCount(1);
    }

    private function handler(
        PasswordResetUserResolver $resolver,
        PasswordResetTokenManagerInterface $tokenManager,
        string $delivery,
    ): PasswordResetRequestHandler {
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['delivery' => $delivery],
        ]);

        return new PasswordResetRequestHandler(
            $resolver,
            $tokenManager,
            new NullPasswordResetNotifier(),
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            $profileRegistry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );
    }

    private function authKitUrlGenerator(): AuthKitUrlGenerator
    {
        return new AuthKitUrlGenerator(
            $this->createMock(UrlGeneratorInterface::class),
            new AuthKitRouteLocaleParameters(new RequestStack(), false, 'en', ['en', 'es']),
        );
    }
}
