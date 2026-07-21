<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Nowo\AuthKitBundle\Controller\UnlocalizedLocaleRedirectController;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class UnlocalizedLocaleRedirectControllerTest extends TestCase
{
    public function testRedirectsToCanonicalRoute(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->expects(self::once())
            ->method('generate')
            ->with('nowo_auth_kit_login', ['_locale' => 'en', 'token' => 'abc'])
            ->willReturn('/en/login');

        $request = Request::create('/login');
        $request->attributes->set('_auth_kit_canonical_route', 'nowo_auth_kit_login');
        $request->attributes->set('_route_params', [
            'token'                     => 'abc',
            '_auth_kit_canonical_route' => 'nowo_auth_kit_login',
            '_auth_kit_profile'         => 'default',
            '_locale'                   => 'en',
        ]);

        $response = (new UnlocalizedLocaleRedirectController(
            AuthKitTestUrlGenerator::fromMock($inner, true),
        ))->redirect($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/en/login', $response->getTargetUrl());
    }

    public function testThrowsWhenCanonicalRouteMissing(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $inner = $this->createMock(UrlGeneratorInterface::class);

        (new UnlocalizedLocaleRedirectController(AuthKitTestUrlGenerator::fromMock($inner)))
            ->redirect(Request::create('/login'));
    }

    public function testIgnoresNonArrayRouteParams(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->expects(self::once())
            ->method('generate')
            ->with('nowo_auth_kit_login', ['_locale' => 'en'])
            ->willReturn('/en/login');

        $request = Request::create('/login');
        $request->attributes->set('_auth_kit_canonical_route', 'nowo_auth_kit_login');
        $request->attributes->set('_route_params', 'invalid');

        $response = (new UnlocalizedLocaleRedirectController(
            AuthKitTestUrlGenerator::fromMock($inner, true),
        ))->redirect($request);

        self::assertSame('/en/login', $response->getTargetUrl());
    }
}
