<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Nowo\AuthKitBundle\Controller\MagicLoginConfirmController;
use Nowo\AuthKitBundle\Form\MagicLoginConfirmType;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

final class MagicLoginConfirmControllerTest extends TestCase
{
    public function testRedirectsWhenInterstitialDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_magic_login_request')->willReturn('/magic-login');

        $controller = $this->controller(
            urlGenerator: AuthKitTestUrlGenerator::fromMock($inner),
            profileRegistry: ProfileRegistryFactory::single(TestUser::class, [
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
            urlGenerator: AuthKitTestUrlGenerator::fromMock($inner),
            profileRegistry: ProfileRegistryFactory::single(TestUser::class, [
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
                return isset($context['magic_login_confirm_form'])
                    && ($context['params']['user'] ?? null) === 'a@b.c'
                    && ($context['action'] ?? null) === '/magic-login/confirm';
            }),
        )->willReturn('<html>confirm</html>');

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(static function (string $name): string {
            return match ($name) {
                'nowo_auth_kit_magic_login_confirm' => '/magic-login/confirm',
                default                             => '/' . $name,
            };
        });

        $controller = $this->controller(
            twig: $twig,
            urlGenerator: AuthKitTestUrlGenerator::fromMock($inner),
            profileRegistry: ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true],
            ]),
        );

        $response = $controller->check(Request::create('/magic-login/check', 'GET', [
            'user'    => 'a@b.c',
            'expires' => '99',
            'hash'    => 'abc',
        ]));

        self::assertSame('<html>confirm</html>', $response->getContent());
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function testConfirmRejectsInvalidCsrf(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>invalid-csrf</html>');

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/magic-login/confirm');

        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->expects(self::never())->method('consumeLoginLink');

        $controller = $this->controller(
            twig: $twig,
            urlGenerator: AuthKitTestUrlGenerator::fromMock($inner),
            profileRegistry: ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true],
            ]),
            loginLinkHandler: $loginLinkHandler,
            csrfValid: false,
        );

        $request = Request::create('/magic-login/confirm', 'POST', [
            'user'        => 'a@b.c',
            'expires'     => '99',
            'hash'        => 'abc',
            '_csrf_token' => 'bad',
        ]);

        $response = $controller->confirm($request);

        self::assertSame('<html>invalid-csrf</html>', $response->getContent());
    }

    public function testConfirmConsumesLoginLinkAndLogsIn(): void
    {
        $user = new TestUser('a@b.c');

        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->expects(self::once())
            ->method('consumeLoginLink')
            ->willReturn($user);

        $security = $this->createMock(Security::class);
        $security->expects(self::once())
            ->method('login')
            ->with($user, 'login_link', 'main')
            ->willReturn(new Response('', Response::HTTP_FOUND, ['Location' => '/dashboard']));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/magic-login/confirm');

        $controller = $this->controller(
            urlGenerator: AuthKitTestUrlGenerator::fromMock($inner),
            profileRegistry: ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true],
            ]),
            security: $security,
            loginLinkHandler: $loginLinkHandler,
            csrfValid: true,
        );

        $request = Request::create('/magic-login/confirm', 'POST', [
            'user'        => 'a@b.c',
            'expires'     => '99',
            'hash'        => 'abc',
            '_csrf_token' => 'ok',
        ]);

        $response = $controller->confirm($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/dashboard', $response->headers->get('Location'));
    }

    public function testConfirmRedirectsWhenLoginLinkInvalid(): void
    {
        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->method('consumeLoginLink')->willThrowException(new InvalidLoginLinkException('bad'));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(static function (string $name): string {
            return match ($name) {
                'nowo_auth_kit_magic_login_request' => '/magic-login',
                default                             => '/magic-login/confirm',
            };
        });

        $controller = $this->controller(
            urlGenerator: AuthKitTestUrlGenerator::fromMock($inner),
            profileRegistry: ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true],
            ]),
            loginLinkHandler: $loginLinkHandler,
            csrfValid: true,
        );

        $request = Request::create('/magic-login/confirm', 'POST', [
            'user'        => 'a@b.c',
            'expires'     => '99',
            'hash'        => 'abc',
            '_csrf_token' => 'ok',
        ]);

        $response = $controller->confirm($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/magic-login', $response->headers->get('Location'));
    }

    private function controller(
        ?Environment $twig = null,
        ?AuthKitUrlGenerator $urlGenerator = null,
        ?ProfileRegistry $profileRegistry = null,
        ?Security $security = null,
        ?LoginLinkHandlerInterface $loginLinkHandler = null,
        bool $csrfValid = true,
    ): MagicLoginConfirmController {
        $registry = $profileRegistry ?? ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true],
        ]);

        return new MagicLoginConfirmController(
            $twig ?? $this->createMock(Environment::class),
            $this->formFactory($csrfValid),
            $urlGenerator ?? AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            new RequestProfileResolver($registry),
            $security ?? $this->createMock(Security::class),
            $loginLinkHandler,
        );
    }

    private function formFactory(bool $csrfValid): FormFactoryInterface
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken(MagicLoginConfirmType::CSRF_TOKEN_ID, 'ok'));
        $csrf->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => $csrfValid && $token->getValue() === 'ok',
        );

        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new CsrfExtension($csrf))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new MagicLoginConfirmType()))
            ->getFormFactory();
    }
}
