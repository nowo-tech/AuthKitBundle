<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Support;

use Nowo\AuthKitBundle\Routing\AuthKitRouteLocaleParameters;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AuthKitTestUrlGenerator
{
    public static function fromMock(UrlGeneratorInterface $inner, bool $localeInPath = false): AuthKitUrlGenerator
    {
        return new AuthKitUrlGenerator(
            $inner,
            new AuthKitRouteLocaleParameters(new RequestStack(), $localeInPath, 'en', ['en', 'es']),
        );
    }
}
