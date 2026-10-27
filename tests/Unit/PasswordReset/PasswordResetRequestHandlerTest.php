<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Locale\RecipientLocale;
use Nowo\AuthKitBundle\PasswordReset\NullPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestedEvent;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetThrottledException;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\PreferredLocaleTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class PasswordResetRequestHandlerTest extends TestCase
{
    public function testHandleDoesNothingWhenUserMissing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);

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
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $result = new PasswordResetTokenResult(
            $user,
            'token',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Link,
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);

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
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $result = new PasswordResetTokenResult(
            $user,
            '123456',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Code,
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);

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
        $user = new TestUser();
        $user->setEmail('ab');
        $result = new PasswordResetTokenResult(
            $user,
            '123456',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Code,
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);

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
        $user = new TestUser();
        $user->setEmail('username');
        $result = new PasswordResetTokenResult(
            $user,
            '123456',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Code,
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);

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
        $repository->method('findBy')->willReturn([]);
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

    public function testThrottledTokenManagerYieldsAThrottledOutcomeWithoutNotifying(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $availableAt  = new DateTimeImmutable('2026-10-23 12:00:00');
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willThrowException(new PasswordResetThrottledException($availableAt));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class);
        $handler         = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $profileRegistry),
            $tokenManager,
            new NullPasswordResetNotifier(),
            $this->authKitUrlGenerator(),
            $dispatcher,
            $profileRegistry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        $outcome = $handler->handle('user@example.com');

        self::assertTrue($outcome->throttled);
        self::assertSame($availableAt, $outcome->availableAt);
    }

    public function testUnknownIdentifierAndRateLimitAreReportedAsAccepted(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $handler = $this->handler(
            new PasswordResetUserResolver($entityManager, ProfileRegistryFactory::single(TestUser::class)),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            'link',
        );

        self::assertFalse($handler->handle('missing@example.com')->throttled);
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

    public function testHandleLocalizesLinkContextAndNotifierForRecipient(): void
    {
        $user   = new PreferredLocaleTestUser('user@example.com', 'es');
        $result = new PasswordResetTokenResult($user, 'token', new DateTimeImmutable('+1 hour'), PasswordResetDeliveryMode::Link);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn($result);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->expects(self::once())->method('generate')
            ->with('nowo_auth_kit_reset_password', ['_locale' => 'es', 'token' => 'token'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.test/es/reset-password/reset/token');

        $request = Request::create('/en/reset-password');
        $request->attributes->set('_locale', 'en');
        $stack = new RequestStack();
        $stack->push($request);

        $translator = new Translator('en');
        $notifier   = new class($translator) implements PasswordResetNotifierInterface {
            /** @var list<string|null> */
            public array $seen = [];

            public function __construct(private readonly Translator $translator)
            {
            }

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                $this->seen = [$context->recipientLocale, $context->resetUrl, $this->translator->getLocale()];
            }
        };

        $registry = ProfileRegistryFactory::single(PreferredLocaleTestUser::class, ['password_reset' => ['delivery' => 'link']]);
        $handler  = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $registry),
            $tokenManager,
            $notifier,
            new AuthKitUrlGenerator($inner, new AuthKitRouteLocaleParameters($stack, 'both', 'en', ['en', 'es'])),
            $this->createMock(EventDispatcherInterface::class),
            $registry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            $stack,
            recipientLocale: new RecipientLocale(
                localeSwitcher: new LocaleSwitcher('en', [$translator]),
                enabled: true,
                allowedLocales: ['en', 'es'],
            ),
        );

        $handler->handle('user@example.com');

        self::assertSame(['es', 'https://example.test/es/reset-password/reset/token', 'es'], $notifier->seen);
        self::assertSame('en', $translator->getLocale());
    }

    public function testHandleLocalizesCodeRouteForRecipient(): void
    {
        $user   = new PreferredLocaleTestUser('user@example.com', 'es');
        $result = new PasswordResetTokenResult($user, '123456', new DateTimeImmutable('+1 hour'), PasswordResetDeliveryMode::Code);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn($result);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->expects(self::once())->method('generate')
            ->with('nowo_auth_kit_reset_password_code', ['_locale' => 'es'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.test/es/reset-password/complete');

        $registry = ProfileRegistryFactory::single(PreferredLocaleTestUser::class, ['password_reset' => ['delivery' => 'code']]);
        $handler  = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $registry),
            $tokenManager,
            new NullPasswordResetNotifier(),
            new AuthKitUrlGenerator($inner, new AuthKitRouteLocaleParameters(new RequestStack(), 'always', 'en', ['en', 'es'])),
            $this->createMock(EventDispatcherInterface::class),
            $registry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
            recipientLocale: new RecipientLocale(enabled: true, allowedLocales: ['en', 'es']),
        );

        $handler->handle('user@example.com');
    }
}
