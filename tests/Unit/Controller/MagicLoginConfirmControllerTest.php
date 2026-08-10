<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use LogicException;
use Nowo\AuthKitBundle\Controller\MagicLoginConfirmController;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class MagicLoginConfirmControllerTest extends TestCase
{
    public function testPostThrowsLogicException(): void
    {
        $controller = $this->controller();

        $this->expectException(LogicException::class);
        $controller->check(Request::create('/magic-login/check', 'POST'));
    }

    public function testRedirectsWhenInterstitialDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_magic_login_request')->willReturn('/magic-login');

        $controller = $this->controller(
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => false],
            ]),
        );

        $response = $controller->check(Request::create('/magic-login/check', 'GET', [
            'user'    => 'a@b.c',
            'expires' => '1',
            'hash'    => 'x',
        ]));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/magic-login', $response->headers->get('Location'));
    }

    public function testRedirectsWhenParamsMissing(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_magic_login_request')->willReturn('/magic-login');

        $controller = $this->controller(
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true],
            ]),
        );

        $response = $controller->check(Request::create('/magic-login/check', 'GET'));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testRendersConfirmForm(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->with(
            '@NowoAuthKitBundle/security/magic_login_confirm.html.twig',
            self::callback(static function (array $context): bool {
                return ($context['params']['user'] ?? null) === 'a@b.c'
                    && ($context['action'] ?? null) === '/magic-login/check';
            }),
        )->willReturn('<html>confirm</html>');

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true],
        ]);

        $controller = new MagicLoginConfirmController(
            $twig,
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            new RequestProfileResolver($profileRegistry),
        );

        $response = $controller->check(Request::create('/magic-login/check', 'GET', [
            'user'    => 'a@b.c',
            'expires' => '99',
            'hash'    => 'abc',
        ]));

        self::assertSame('<html>confirm</html>', $response->getContent());
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    private function controller(
        ?AuthKitUrlGenerator $urlGenerator = null,
        ?ProfileRegistry $profileRegistry = null,
    ): MagicLoginConfirmController {
        $registry = $profileRegistry ?? ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true],
        ]);

        return new MagicLoginConfirmController(
            $this->createMock(Environment::class),
            $urlGenerator ?? AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            new RequestProfileResolver($registry),
        );
    }
}
