<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Routing;

use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class AuthKitUrlGeneratorTest extends TestCase
{
    public function testGenerateMergesLocaleParameters(): void
    {
        $request = Request::create('/es/login');
        $request->attributes->set('_locale', 'es');
        $stack = new RequestStack();
        $stack->push($request);

        $localeParameters = new AuthKitRouteLocaleParameters($stack, true, 'en', ['en', 'es']);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('nowo_auth_kit_login', ['_locale' => 'es'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/es/login');

        $generator = new AuthKitUrlGenerator($urlGenerator, $localeParameters);

        self::assertSame('/es/login', $generator->generate('nowo_auth_kit_login'));
    }

    public function testAbsoluteUrlsUseBaseUriWhilePathsUseRequestRouter(): void
    {
        $routes = new RouteCollection();
        $routes->add('nowo_auth_kit_login', new Route('/login'));
        $router = new UrlGenerator($routes, (new RequestContext())->fromRequest(Request::create('http://evil.example/login')));

        $generator = new AuthKitUrlGenerator(
            $router,
            new AuthKitRouteLocaleParameters(new RequestStack(), false, 'en', ['en']),
            new AbsoluteUrlGenerator($router, 'https://app.example.com'),
        );

        self::assertSame('https://app.example.com/login', $generator->generate('nowo_auth_kit_login', [], UrlGeneratorInterface::ABSOLUTE_URL));
        self::assertSame('//app.example.com/login', $generator->generate('nowo_auth_kit_login', [], UrlGeneratorInterface::NETWORK_PATH));
        self::assertSame('/login', $generator->generate('nowo_auth_kit_login'));
    }
}
