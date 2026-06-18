<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Routing;

use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthKitRouteLocaleParametersTest extends TestCase
{
    public function testMergeReturnsEmptyWhenLocaleInPathDisabled(): void
    {
        $parameters = $this->createParameters(false)->merge(['token' => 'abc']);

        self::assertSame(['token' => 'abc'], $parameters);
    }

    public function testMergeUsesRequestLocaleFromRouteAttribute(): void
    {
        $request = Request::create('/es/login');
        $request->attributes->set('_locale', 'es');

        $stack = new RequestStack();
        $stack->push($request);

        $parameters = (new AuthKitRouteLocaleParameters($stack, true, 'en', ['en', 'es']))->merge();

        self::assertSame(['_locale' => 'es'], $parameters);
    }

    public function testMergeUsesDefaultLocaleWhenNoRequest(): void
    {
        $parameters = $this->createParameters(true)->merge();

        self::assertSame(['_locale' => 'en'], $parameters);
    }

    public function testMergeDoesNotOverrideExplicitLocale(): void
    {
        $parameters = $this->createParameters(true)->merge(['_locale' => 'es']);

        self::assertSame(['_locale' => 'es'], $parameters);
    }

    public function testAccessControlPatternWithoutLocale(): void
    {
        self::assertSame('^\/login', $this->createParameters(false)->accessControlPattern('/login'));
    }

    public function testAccessControlPatternWithLocaleAndTokenPlaceholder(): void
    {
        $pattern = $this->createParameters(true)->accessControlPattern('/reset-password/reset/{token}');

        self::assertSame('^/(en|es)\/reset\-password\/reset\/[^/]+', $pattern);
    }

    private function createParameters(bool $localeInPath): AuthKitRouteLocaleParameters
    {
        return new AuthKitRouteLocaleParameters(new RequestStack(), $localeInPath, 'en', ['en', 'es']);
    }
}
