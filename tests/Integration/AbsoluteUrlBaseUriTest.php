<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Locale\RecipientLocale;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotifierInterface;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginRequestHandler;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginUserResolver;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\PreferredLocaleTestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Password-reset poisoning: a forged Host header must not reach mailed links once absolute_url.base_uri is set.
 *
 * Wires the real Symfony router, AuthKitUrlGenerator, request handlers and LoginLinkHandler.
 */
final class AbsoluteUrlBaseUriTest extends TestCase
{
    public function testForgedHostLeaksIntoResetLinkWithoutBaseUri(): void
    {
        [$router, $stack] = $this->forgedRequest();

        self::assertSame(
            'http://evil.example/reset-password/reset/tok',
            $this->resetUrl($router, $stack, null, PasswordResetDeliveryMode::Link),
        );
    }

    public function testForgedHostDoesNotChangeResetLinkWithBaseUri(): void
    {
        [$router, $stack] = $this->forgedRequest();
        $original         = $router->getContext();

        self::assertSame(
            'https://app.example.com/portal/reset-password/reset/tok',
            $this->resetUrl($router, $stack, 'https://app.example.com/portal', PasswordResetDeliveryMode::Link),
        );
        self::assertSame($original, $router->getContext());
    }

    public function testForgedHostDoesNotChangeResetCodePageLinkWithBaseUri(): void
    {
        [$router, $stack] = $this->forgedRequest();

        self::assertSame(
            'https://app.example.com/reset-password/complete',
            $this->resetUrl($router, $stack, 'https://app.example.com', PasswordResetDeliveryMode::Code),
        );
    }

    public function testForgedHostLeaksIntoMagicLinkWithoutBaseUri(): void
    {
        [$router, $stack] = $this->forgedRequest();

        self::assertStringStartsWith('http://evil.example/es/magic-login/check?', $this->magicUrl($router, $stack, new AbsoluteUrlGenerator($router)));
    }

    public function testForgedHostDoesNotChangeMagicLinkWithBaseUri(): void
    {
        [$router, $stack] = $this->forgedRequest();
        $original         = $router->getContext();

        $url = $this->magicUrl($router, $stack, new AbsoluteUrlGenerator($router, 'https://app.example.com:8443'));

        self::assertStringStartsWith('https://app.example.com:8443/es/magic-login/check?user=user@example.com', $url);
        self::assertSame($original, $router->getContext());
        self::assertSame('evil.example', $router->getContext()->getHost());
    }

    public function testMagicLinkWithBaseUriFallsBackToRequestLocale(): void
    {
        [$router, $stack] = $this->forgedRequest();

        $url = $this->magicUrl($router, $stack, new AbsoluteUrlGenerator($router, 'https://app.example.com'), null);

        self::assertStringStartsWith('https://app.example.com/en/magic-login/check?', $url);
    }

    public function testMagicLinkWithBaseUriAndNoRequestKeepsRouterParameters(): void
    {
        [$router] = $this->forgedRequest();
        $router->getContext()->setParameter('_locale', 'en');

        $url = $this->magicUrl($router, new RequestStack(), new AbsoluteUrlGenerator($router, 'https://app.example.com'), null);

        self::assertStringStartsWith('https://app.example.com/en/magic-login/check?', $url);
    }

    /**
     * @return array{UrlGenerator, RequestStack}
     */
    private function forgedRequest(): array
    {
        $routes = new RouteCollection();
        $routes->add('nowo_auth_kit_reset_password', new Route('/reset-password/reset/{token}'));
        $routes->add('nowo_auth_kit_reset_password_code', new Route('/reset-password/complete'));
        $routes->add('nowo_auth_kit_magic_login_check', new Route('/{_locale}/magic-login/check'));

        $request = Request::create('/en/reset-password', 'POST', server: ['HTTP_HOST' => 'evil.example']);
        $request->setLocale('en');
        $request->attributes->set('_locale', 'en');
        $stack = new RequestStack();
        $stack->push($request);

        return [new UrlGenerator($routes, (new RequestContext())->fromRequest($request)), $stack];
    }

    private function resetUrl(UrlGenerator $router, RequestStack $stack, ?string $baseUri, PasswordResetDeliveryMode $mode): string
    {
        $user       = new PreferredLocaleTestUser('user@example.com');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $token        = $mode === PasswordResetDeliveryMode::Link ? 'tok' : '123456';
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn(new PasswordResetTokenResult($user, $token, new DateTimeImmutable('+1 hour'), $mode));

        $notifier = new class implements PasswordResetNotifierInterface {
            public string $url = '';

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                $this->url = $context->resetUrl;
            }
        };

        $registry = ProfileRegistryFactory::single(PreferredLocaleTestUser::class, ['password_reset' => ['delivery' => $mode->value]]);
        (new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $registry),
            $tokenManager,
            $notifier,
            new AuthKitUrlGenerator(
                $router,
                new AuthKitRouteLocaleParameters($stack, false, 'en', ['en', 'es']),
                new AbsoluteUrlGenerator($router, $baseUri),
            ),
            $this->createMock(EventDispatcherInterface::class),
            $registry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            $stack,
        ))->handle('user@example.com');

        return $notifier->url;
    }

    private function magicUrl(UrlGenerator $router, RequestStack $stack, AbsoluteUrlGenerator $absolute, ?string $preferredLocale = 'es'): string
    {
        $user       = new PreferredLocaleTestUser('user@example.com', $preferredLocale);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $notifier = new class implements MagicLoginNotifierInterface {
            public string $url = '';

            public function notify(MagicLoginNotificationContext $context): void
            {
                $this->url = $context->loginUrl;
            }
        };

        $loginLinkHandler = new LoginLinkHandler(
            $router,
            $this->createMock(UserProviderInterface::class),
            new SignatureHasher(PropertyAccess::createPropertyAccessor(), [], 'secret'),
            ['route_name' => 'nowo_auth_kit_magic_login_check', 'lifetime' => 600],
        );

        $registry = ProfileRegistryFactory::single(PreferredLocaleTestUser::class, [
            'magic_login' => ['mode' => 'enabled', 'lifetime' => 600, 'max_uses' => 1],
        ]);
        (new MagicLoginRequestHandler(
            new MagicLoginUserResolver($entityManager, $registry),
            $notifier,
            $this->createMock(EventDispatcherInterface::class),
            $registry,
            $stack,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            $loginLinkHandler,
            recipientLocale: new RecipientLocale(enabled: true, allowedLocales: ['en', 'es']),
            absoluteUrlGenerator: $absolute,
        ))->handle('user@example.com');

        return $notifier->url;
    }
}
