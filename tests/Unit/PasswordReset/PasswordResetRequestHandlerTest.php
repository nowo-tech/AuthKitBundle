<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\NullPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestedEvent;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
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
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
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

        $notifier = new class implements \Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface {
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
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            $tokenManager,
            $notifier,
            $urlGenerator,
            $dispatcher,
            $this->routes(),
            'link',
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

        $notifier = new class implements \Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface {
            public ?PasswordResetNotificationContext $context = null;

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                $this->context = $context;
            }
        };

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            $tokenManager,
            $notifier,
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            $this->routes(),
            'code',
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

        $notifier = new class implements \Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface {
            public ?PasswordResetNotificationContext $context = null;

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                $this->context = $context;
            }
        };

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            $tokenManager,
            $notifier,
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            $this->routes(),
            'code',
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

        $notifier = new class implements \Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface {
            public ?PasswordResetNotificationContext $context = null;

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                $this->context = $context;
            }
        };

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            $tokenManager,
            $notifier,
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            $this->routes(),
            'code',
        );

        $handler->handle('username');

        self::assertSame('u******e', $notifier->context?->maskedIdentifier);
    }

    private function handler(
        PasswordResetUserResolver $resolver,
        PasswordResetTokenManagerInterface $tokenManager,
        string $delivery,
    ): PasswordResetRequestHandler {
        return new PasswordResetRequestHandler(
            $resolver,
            $tokenManager,
            new NullPasswordResetNotifier(),
            $this->authKitUrlGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            $this->routes(),
            $delivery,
        );
    }

    private function authKitUrlGenerator(): AuthKitUrlGenerator
    {
        return new AuthKitUrlGenerator(
            $this->createMock(UrlGeneratorInterface::class),
            new AuthKitRouteLocaleParameters(new RequestStack(), false, 'en', ['en', 'es']),
        );
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    private function routes(): array
    {
        return [
            'reset_password'      => ['path' => '/reset/{token}', 'name' => 'nowo_auth_kit_reset_password'],
            'reset_password_code' => ['path' => '/complete', 'name' => 'nowo_auth_kit_reset_password_code'],
        ];
    }
}
