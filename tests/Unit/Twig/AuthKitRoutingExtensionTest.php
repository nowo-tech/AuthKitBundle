<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Twig;

use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Twig\AuthKitRoutingExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthKitRoutingExtensionTest extends TestCase
{
    public function testRouteParametersDelegatesToLocaleParameters(): void
    {
        $localeParameters = new AuthKitRouteLocaleParameters(new RequestStack(), true, 'en', ['en', 'es']);
        $extension        = new AuthKitRoutingExtension($localeParameters);

        self::assertSame(['_locale' => 'en'], $extension->routeParameters());
        self::assertSame(['_locale' => 'es', 'token' => 'abc'], $extension->routeParameters(['_locale' => 'es', 'token' => 'abc']));
    }
}
