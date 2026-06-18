<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Routing;

use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
}
