<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Routing;

use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Nowo\AuthKitBundle\Routing\PublicUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class PublicUrlGeneratorTest extends TestCase
{
    public function testAbsoluteUrlsUseTheBaseUriNotTheForgedHost(): void
    {
        $router    = $this->router('http://evil.example/login');
        $generator = new PublicUrlGenerator($router, new AbsoluteUrlGenerator($router, 'https://app.example.com'));

        self::assertSame('https://app.example.com/issues/7', $generator->generate('issue', ['id' => 7], UrlGeneratorInterface::ABSOLUTE_URL));
        self::assertSame('//app.example.com/issues/7', $generator->generate('issue', ['id' => 7], UrlGeneratorInterface::NETWORK_PATH));
    }

    public function testRelativeReferencesUseTheRouter(): void
    {
        $router    = $this->router('http://evil.example/login');
        $generator = new PublicUrlGenerator($router, new AbsoluteUrlGenerator($router, 'https://app.example.com'));

        self::assertSame('/issues/7', $generator->generate('issue', ['id' => 7]));
        self::assertSame('issues/7', $generator->generate('issue', ['id' => 7], UrlGeneratorInterface::RELATIVE_PATH));
    }

    public function testContextIsTheRouterContext(): void
    {
        $router    = $this->router('http://evil.example/login');
        $generator = new PublicUrlGenerator($router, new AbsoluteUrlGenerator($router));
        $context   = new RequestContext('', 'GET', 'other.example');

        $generator->setContext($context);

        self::assertSame($context, $generator->getContext());
        self::assertSame($context, $router->getContext());
    }

    private function router(string $requestUrl): UrlGeneratorInterface
    {
        $routes = new RouteCollection();
        $routes->add('issue', new Route('/issues/{id}'));

        return new UrlGenerator($routes, new RequestContext()->fromRequest(Request::create($requestUrl)));
    }
}
