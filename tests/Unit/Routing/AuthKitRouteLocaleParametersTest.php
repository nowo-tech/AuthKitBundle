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

    /**
     * SEC-004 L3: a request locale from input (`/evil.example`) never reaches a generated path, which would make
     * `/{_locale}/login` the protocol-relative `//evil.example/login`.
     */
    public function testMergeIgnoresALocaleThatIsNotEnabled(): void
    {
        $request = Request::create('/login');
        $request->attributes->set('_locale', '/evil.example');
        $request->setLocale('xx');
        $stack = new RequestStack();
        $stack->push($request);

        $parameters = new AuthKitRouteLocaleParameters($stack, true, 'en', ['en', 'es']);

        self::assertSame(['_locale' => 'en'], $parameters->merge());
        self::assertSame(['_locale' => 'en'], $parameters->merge([], '/evil.example'));
        self::assertSame(['_locale' => 'es'], $parameters->merge([], 'es'));
    }

    public function testWithoutEnabledLocalesOnlyLocaleShapedValuesAreAccepted(): void
    {
        $request = Request::create('/login');
        $request->attributes->set('_locale', '/evil.example');
        $request->setLocale('pt_BR');
        $stack = new RequestStack();
        $stack->push($request);

        self::assertSame(['_locale' => 'pt_BR'], (new AuthKitRouteLocaleParameters($stack, true, 'en', []))->merge());
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

    public function testAccessControlPatternsBothModes(): void
    {
        $patterns = (new AuthKitRouteLocaleParameters(new RequestStack(), 'both', 'en', ['en', 'es']))
            ->accessControlPatterns('/login');

        self::assertSame([
            '^/(en|es)\/login',
            '^\/login',
        ], $patterns);
    }

    public function testMergeInjectsLocaleWhenBoth(): void
    {
        $parameters = (new AuthKitRouteLocaleParameters(new RequestStack(), 'both', 'en', ['en', 'es']))->merge();

        self::assertSame(['_locale' => 'en'], $parameters);
    }

    private function createParameters(bool $localeInPath): AuthKitRouteLocaleParameters
    {
        return new AuthKitRouteLocaleParameters(new RequestStack(), $localeInPath, 'en', ['en', 'es']);
    }

    public function testMergeUsesExplicitRecipientLocaleOnlyWithLocalePrefix(): void
    {
        $request = Request::create('/en/reset-password');
        $request->attributes->set('_locale', 'en');
        $stack = new RequestStack();
        $stack->push($request);

        $prefixed = new AuthKitRouteLocaleParameters($stack, 'both', 'en', ['en', 'es']);
        self::assertSame(['_locale' => 'es', 'token' => 't'], $prefixed->merge(['token' => 't'], 'es'));
        self::assertSame(['_locale' => 'en'], $prefixed->merge([], ''));
        self::assertSame(['_locale' => 'fr'], $prefixed->merge(['_locale' => 'fr'], 'es'));

        $bare = new AuthKitRouteLocaleParameters($stack, 'never', 'en', ['en', 'es']);
        self::assertSame(['token' => 't'], $bare->merge(['token' => 't'], 'es'));
    }
}
