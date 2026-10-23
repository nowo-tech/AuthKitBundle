<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\ResetPasswordController;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Twig\Environment;

/**
 * password_reset.token_in_session: the link token moves into the session and the form renders on the token-less URL.
 */
final class ResetPasswordTokenInSessionTest extends TestCase
{
    private const KEY = ResetPasswordController::SESSION_TOKEN_KEY . 'default';

    public function testStoresTheTokenAndRedirectsToTheTokenLessUrl(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->expects(self::never())->method('resolveUserByLinkToken');

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->expects(self::once())->method('generate')
            ->with('nowo_auth_kit_reset_password', ['token' => null])
            ->willReturn('/reset-password/reset');

        $request  = $this->request('/reset-password/reset/abc');
        $response = $this->controller($tokenManager, $inner)->reset($request, 'abc');

        self::assertSame('/reset-password/reset', $response->headers->get('Location'));
        self::assertSame('abc', $request->getSession()->get(self::KEY));
    }

    public function testRendersTheFormWithTheSessionTokenAndClearsItOnSuccess(): void
    {
        $user         = new TestUser();
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->expects(self::exactly(2))->method('resolveUserByLinkToken')->with('abc', 'default')->willReturn($user);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('form');

        $request = $this->request('/reset-password/reset');
        $request->getSession()->set(self::KEY, 'abc');

        $pending = $this->controller($tokenManager, $inner, $this->form(false), $twig)->reset($request);
        self::assertSame('form', $pending->getContent());
        self::assertSame('abc', $request->getSession()->get(self::KEY));

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');
        $done = $this->controller($tokenManager, $inner, $this->form(true), $twig, $hasher)->reset($request);

        self::assertSame('/login', $done->headers->get('Location'));
        self::assertSame('hashed', $user->getPassword());
        self::assertFalse($request->getSession()->has(self::KEY));
    }

    public function testMissingOrInvalidSessionTokenRedirectsToTheRequestPage(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('resolveUserByLinkToken')->willReturn(null);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_reset_password_request')->willReturn('/reset-password');

        $empty = $this->request('/reset-password/reset');
        self::assertSame('/reset-password', $this->controller($tokenManager, $inner)->reset($empty)->headers->get('Location'));

        $stale = $this->request('/reset-password/reset');
        $stale->getSession()->set(self::KEY, 'expired');
        self::assertSame('/reset-password', $this->controller($tokenManager, $inner)->reset($stale)->headers->get('Location'));
        self::assertFalse($stale->getSession()->has(self::KEY));
    }

    public function testWithoutSessionTheUrlTokenIsUsedDirectly(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->expects(self::once())->method('resolveUserByLinkToken')->with('abc')->willReturn(null);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/reset-password');

        $this->controller($tokenManager, $inner)->reset(Request::create('/reset-password/reset/abc'), 'abc');
    }

    private function request(string $uri): Request
    {
        $request = Request::create($uri);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function form(bool $submitted): FormInterface
    {
        $form = $this->createMock(FormInterface::class);
        $form->method('isSubmitted')->willReturn($submitted);
        $form->method('isValid')->willReturn($submitted);
        $form->method('getData')->willReturn(['password' => 'secret12']);
        $form->method('createView')->willReturn(new FormView());

        return $form;
    }

    private function controller(
        PasswordResetTokenManagerInterface $tokenManager,
        UrlGeneratorInterface $inner,
        ?FormInterface $form = null,
        ?Environment $twig = null,
        ?UserPasswordHasherInterface $hasher = null,
    ): ResetPasswordController {
        $overrides       = ['password_reset' => ['mode' => 'enabled', 'token_in_session' => true]];
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, $overrides);
        $formFactory     = $this->createMock(FormFactoryInterface::class);
        if ($form instanceof FormInterface) {
            $formFactory->method('create')->willReturn($form);
        }

        return new ResetPasswordController(
            $twig ?? $this->createMock(Environment::class),
            $formFactory,
            new PasswordResetGate($profileRegistry),
            $tokenManager,
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $hasher ?? $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $tokenManager,
                $profileRegistry,
            ),
            new TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $overrides),
        );
    }
}
