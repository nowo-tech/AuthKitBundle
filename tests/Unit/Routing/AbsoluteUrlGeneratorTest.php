<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Routing;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class AbsoluteUrlGeneratorTest extends TestCase
{
    public function testWithoutBaseUriUsesRequestHost(): void
    {
        $router    = $this->router('http://evil.example/login');
        $generator = new AbsoluteUrlGenerator($router);

        self::assertFalse($generator->isEnabled());
        self::assertSame('http://evil.example/reset/abc', $generator->generate('reset', ['token' => 'abc']));
    }

    public function testEmptyBaseUriKeepsRequestHost(): void
    {
        $generator = new AbsoluteUrlGenerator($this->router('http://evil.example/login'), '  ');

        self::assertFalse($generator->isEnabled());
        self::assertSame('http://evil.example/reset/abc', $generator->generate('reset', ['token' => 'abc']));
    }

    public function testBaseUriReplacesForgedHostAndRestoresContext(): void
    {
        $router   = $this->router('http://evil.example/login');
        $original = $router->getContext();

        $generator = new AbsoluteUrlGenerator($router, 'https://app.example.com');

        self::assertTrue($generator->isEnabled());
        self::assertSame('https://app.example.com/reset/abc', $generator->generate('reset', ['token' => 'abc']));
        self::assertSame($original, $router->getContext());
        self::assertSame('evil.example', $router->getContext()->getHost());
        self::assertSame('http://evil.example/reset/abc', $router->generate('reset', ['token' => 'abc'], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    public function testBaseUriPortAndPathPrefix(): void
    {
        $generator = new AbsoluteUrlGenerator($this->router('https://evil.example:444/app/login'), 'https://app.example.com:8443/portal/');

        self::assertSame('https://app.example.com:8443/portal/reset/abc', $generator->generate('reset', ['token' => 'abc']));
    }

    public function testHttpBaseUriWithPort(): void
    {
        $generator = new AbsoluteUrlGenerator($this->router('https://evil.example/login'), 'HTTP://localhost:8010');

        self::assertSame('http://localhost:8010/reset/abc', $generator->generate('reset', ['token' => 'abc']));
    }

    public function testNetworkPathUsesBaseHost(): void
    {
        $generator = new AbsoluteUrlGenerator($this->router('http://evil.example/login'), 'https://app.example.com');

        self::assertSame('//app.example.com/reset/abc', $generator->generate('reset', ['token' => 'abc'], UrlGeneratorInterface::NETWORK_PATH));
    }

    public function testContextParametersAreKeptAndMerged(): void
    {
        $router = $this->router('http://evil.example/login');
        $router->getContext()->setParameter('_locale', 'en');
        $router->getContext()->setParameter('keep', 'yes');

        $generator = new AbsoluteUrlGenerator($router, 'https://app.example.com');

        $seen = $generator->withBaseContext(
            static fn (): array => $router->getContext()->getParameters(),
            ['_locale' => 'es'],
        );

        self::assertSame(['_locale' => 'es', 'keep' => 'yes'], $seen);
        self::assertSame('https://app.example.com/es/check', $generator->withBaseContext(
            static fn (): string => $router->generate('localized', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ['_locale' => 'es'],
        ));
        self::assertSame('en', $router->getContext()->getParameter('_locale'));
    }

    public function testWithBaseContextWithoutBaseRunsCallbackUntouched(): void
    {
        $router    = $this->router('http://evil.example/login');
        $original  = $router->getContext();
        $generator = new AbsoluteUrlGenerator($router);

        self::assertSame('evil.example', $generator->withBaseContext(static fn (): string => $router->getContext()->getHost(), ['_locale' => 'es']));
        self::assertSame($original, $router->getContext());
        self::assertFalse($original->hasParameter('_locale'));
    }

    public function testContextIsRestoredWhenCallbackThrows(): void
    {
        $router    = $this->router('http://evil.example/login');
        $original  = $router->getContext();
        $generator = new AbsoluteUrlGenerator($router, 'https://app.example.com');

        $caught = null;
        try {
            $generator->withBaseContext(static function () use ($router): string {
                if ($router->getContext()->getHost() === 'app.example.com') {
                    throw new RuntimeException('boom');
                }

                return 'unexpected';
            });
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame('boom', $caught->getMessage());
        self::assertSame($original, $router->getContext());
        self::assertSame('evil.example', $router->getContext()->getHost());
    }

    public function testConstructorRejectsInvalidBaseUri(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the scheme must be http or https.');

        new AbsoluteUrlGenerator($this->router('http://evil.example/login'), 'ftp://app.example.com');
    }

    #[DataProvider('validationCases')]
    public function testValidationError(string $baseUri, ?string $expected): void
    {
        self::assertSame($expected, AbsoluteUrlGenerator::validationError($baseUri));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function validationCases(): iterable
    {
        yield 'https' => ['https://app.example.com', null];
        yield 'http with port and path' => ['http://localhost:8010/app/', null];
        yield 'relative' => ['/app', 'expected an absolute URL such as "https://app.example.com".'];
        yield 'no host' => ['https://', 'expected an absolute URL such as "https://app.example.com".'];
        yield 'bad scheme' => ['javascript://app.example.com', 'the scheme must be http or https.'];
        yield 'user info' => ['https://user:pass@app.example.com', 'user info is not allowed.'];
        yield 'query' => ['https://app.example.com/?a=1', 'query strings and fragments are not allowed.'];
        yield 'fragment' => ['https://app.example.com/#x', 'query strings and fragments are not allowed.'];
    }

    public function testIsEnvPlaceholder(): void
    {
        self::assertTrue(AbsoluteUrlGenerator::isEnvPlaceholder('env_0123456789abcdef_DEFAULT_URI_0123456789abcdef0123456789abcdef'));
        self::assertTrue(AbsoluteUrlGenerator::isEnvPlaceholder('https://env_0123456789abcdef_HOST_0123456789abcdef0123456789abcdef'));
        self::assertFalse(AbsoluteUrlGenerator::isEnvPlaceholder('https://app.example.com'));
        self::assertFalse(AbsoluteUrlGenerator::isEnvPlaceholder(null));
    }

    private function router(string $requestUri): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('reset', new Route('/reset/{token}'));
        $routes->add('localized', new Route('/{_locale}/check'));

        return new UrlGenerator($routes, (new RequestContext())->fromRequest(Request::create($requestUri)));
    }
}
