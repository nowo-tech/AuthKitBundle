<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\EventSubscriber;

use Nowo\AuthKitBundle\EventSubscriber\LocalizedLogoutRedirectSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class LocalizedLogoutRedirectSubscriberTest extends TestCase
{
    public function testSubscribesBeforeTheFirewall(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => [['onKernelRequest', 9]]],
            LocalizedLogoutRedirectSubscriber::getSubscribedEvents(),
        );
    }

    public function testRedirectsTheLocalizedLogoutToTheBareUrlKeepingTheQuery(): void
    {
        $event = $this->event('/es/logout?_csrf_token=abc%2Fdef&x=1', 'nowo_auth_kit_logout');
        $this->subscriber()->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/logout?_csrf_token=abc%2Fdef&x=1', $response->getTargetUrl());
    }

    public function testEveryProfileLogoutRouteAndAPostKeepsItsMethod(): void
    {
        $event = $this->event('/es/admin/logout', 'admin_logout', 'POST');
        $this->subscriber()->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(307, $response->getStatusCode());
        self::assertSame('/admin/logout', $response->getTargetUrl());
    }

    public function testAQueryCannotTurnTheTargetIntoAnotherHost(): void
    {
        $event = $this->event('/es/logout?next=//evil.example/x&_target_path=https://evil.example', 'nowo_auth_kit_logout');
        $this->subscriber()->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringStartsWith('/logout?', $response->getTargetUrl());
    }

    /**
     * @return iterable<string, array{0: bool|string, 1: string, 2: bool, 3: string}>
     */
    public static function inactiveProvider(): iterable
    {
        yield 'disabled' => ['both', 'serve', false, 'nowo_auth_kit_logout'];
        yield 'unlocalized redirect' => ['both', 'redirect', true, 'nowo_auth_kit_logout'];
        yield 'always' => ['always', 'serve', true, 'nowo_auth_kit_logout'];
        yield 'legacy bool' => [true, 'serve', true, 'nowo_auth_kit_logout'];
        yield 'never' => [false, 'serve', true, 'nowo_auth_kit_logout'];
        yield 'bare twin' => ['both', 'serve', true, 'nowo_auth_kit_logout_unlocalized'];
        yield 'other route' => ['both', 'serve', true, 'nowo_auth_kit_login'];
    }

    #[DataProvider('inactiveProvider')]
    public function testDoesNothingOutsideItsScope(string|bool $inPath, string $unlocalized, bool $enabled, string $route): void
    {
        $event = $this->event('/es/logout', $route);
        $this->subscriber($inPath, $unlocalized, $enabled)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequestsAndMissingRoutes(): void
    {
        $subscriber = $this->subscriber();

        $sub = $this->event('/es/logout', 'nowo_auth_kit_logout', 'GET', HttpKernelInterface::SUB_REQUEST);
        $subscriber->onKernelRequest($sub);
        self::assertNull($sub->getResponse());

        $noRoute = $this->event('/es/logout', null);
        $subscriber->onKernelRequest($noRoute);
        self::assertNull($noRoute->getResponse());
    }

    public function testNoBareTwinOrUnsafeTargetLeavesTheRequestAlone(): void
    {
        $missing = $this->createStub(UrlGeneratorInterface::class);
        $missing->method('generate')->willThrowException(new RouteNotFoundException('missing'));
        $event = $this->event('/es/logout', 'nowo_auth_kit_logout');
        $this->subscriber(generator: $missing)->onKernelRequest($event);
        self::assertNull($event->getResponse());

        foreach (['//evil.example/logout', '/\\evil.example', 'https://evil.example/logout'] as $unsafe) {
            $generator = $this->createStub(UrlGeneratorInterface::class);
            $generator->method('generate')->willReturn($unsafe);
            $event = $this->event('/es/logout', 'nowo_auth_kit_logout');
            $this->subscriber(generator: $generator)->onKernelRequest($event);
            self::assertNull($event->getResponse(), $unsafe);
        }
    }

    public function testNonArrayRouteParamsAndAQueryInTheGeneratedUrl(): void
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturn('/logout?a=1');
        $event = $this->event('/es/logout?b=2', 'nowo_auth_kit_logout');
        $event->getRequest()->attributes->set('_route_params', 'invalid');
        $this->subscriber(generator: $generator)->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/logout?a=1&b=2', $response->getTargetUrl());
    }

    private function subscriber(
        string|bool $inPath = 'both',
        string $unlocalized = 'serve',
        bool $enabled = true,
        ?UrlGeneratorInterface $generator = null,
    ): LocalizedLogoutRedirectSubscriber {
        $profiles = [
            'default' => ['routes' => ['logout' => ['name' => 'nowo_auth_kit_logout', 'path' => '/logout']]],
            'admin'   => ['routes' => ['logout' => ['name' => 'admin_logout', 'path' => '/admin/logout']]],
            'dup'     => ['routes' => ['logout' => ['name' => 'nowo_auth_kit_logout', 'path' => '/logout']]],
            'broken'  => ['routes' => []],
        ];

        return new LocalizedLogoutRedirectSubscriber($generator ?? $this->generator(), $profiles, $inPath, $unlocalized, $enabled);
    }

    private function generator(): UrlGeneratorInterface
    {
        $routes = new RouteCollection();
        $routes->add('nowo_auth_kit_logout', new Route('/{_locale}/logout', ['_auth_kit_profile' => 'default'], ['_locale' => 'en|es']));
        $routes->add('nowo_auth_kit_logout_unlocalized', new Route('/logout', ['_locale' => 'en', '_auth_kit_profile' => 'default']));
        $routes->add('admin_logout', new Route('/{_locale}/admin/logout', ['_auth_kit_profile' => 'admin'], ['_locale' => 'en|es']));
        $routes->add('admin_logout_unlocalized', new Route('/admin/logout', ['_locale' => 'en', '_auth_kit_profile' => 'admin']));

        return new UrlGenerator($routes, new RequestContext());
    }

    private function event(string $uri, ?string $route, string $method = 'GET', int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create($uri, $method);
        if ($route !== null) {
            $request->attributes->set('_route', $route);
            $request->attributes->set('_route_params', ['_locale' => 'es', '_auth_kit_profile' => 'default']);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
