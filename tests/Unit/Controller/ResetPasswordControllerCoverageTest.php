<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\ResetPasswordController;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ResetPasswordControllerCoverageTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testRedirectsAuthenticatedUser(): void
    {
        $storage = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new TestUser(), 'main', ['ROLE_USER']));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('demo_home')->willReturn('/home');

        $controller = $this->buildController(
            $this->createMock(PasswordResetTokenManagerInterface::class),
            AuthKitTestUrlGenerator::fromMock($inner),
            $storage,
            'demo_home',
        );

        $response = $controller->reset(new Request(), 'token');

        self::assertSame('/home', $response->headers->get('Location'));
    }

    public function testRedirectsWhenResetDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $controller = $this->buildController(
            $this->createMock(PasswordResetTokenManagerInterface::class),
            AuthKitTestUrlGenerator::fromMock($inner),
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            null,
            new PasswordResetGate('disabled'),
        );

        $response = $controller->reset(new Request(), 'token');

        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testInvalidTokenWithSessionAddsFlash(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('resolveUserByLinkToken')->willReturn(null);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_reset_password_request')->willReturn('/reset-password');

        $request = Request::create('/reset-password/reset/bad');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = $this->buildController($tokenManager, AuthKitTestUrlGenerator::fromMock($inner), new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(), null);

        $controller->reset($request, 'bad');

        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertTrue($session->getFlashBag()->has('error'));
    }

    public function testInvalidTokenWithoutSessionStillRedirects(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('resolveUserByLinkToken')->willReturn(null);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_reset_password_request')->willReturn('/reset-password');

        $controller = $this->buildController($tokenManager, AuthKitTestUrlGenerator::fromMock($inner), new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(), null);

        $response = $controller->reset(new Request(), 'bad');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testCompletesPasswordResetOnValidSubmit(): void
    {
        $user         = new TestUser();
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('resolveUserByLinkToken')->willReturn($user);
        $tokenManager->expects(self::once())->method('clearForUser');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest');
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn(['password' => 'secret12']);
        $form->method('createView')->willReturn(new FormView());

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $request = Request::create('/reset', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new ResetPasswordController(
            $this->createMock(\Twig\Environment::class),
            $formFactory,
            new PasswordResetGate('enabled'),
            $tokenManager,
            new PasswordResetCompleter($entityManager, $hasher, new PropertyAccessor(), $tokenManager, FieldConfigNormalizerFields::registration()),
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->templates(),
            $this->routes(),
            null,
        );

        $response = $controller->reset($request, 'valid');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('hashed', $user->getPassword());
    }

    private function buildController(
        PasswordResetTokenManagerInterface $tokenManager,
        AuthKitUrlGenerator $urlGenerator,
        \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage $tokenStorage,
        ?string $successRoute,
        ?PasswordResetGate $gate = null,
    ): ResetPasswordController {
        return new ResetPasswordController(
            $this->createMock(\Twig\Environment::class),
            $this->createMock(FormFactoryInterface::class),
            $gate ?? new PasswordResetGate('enabled'),
            $tokenManager,
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $tokenManager,
                FieldConfigNormalizerFields::registration(),
            ),
            $tokenStorage,
            $urlGenerator,
            $this->templates(),
            $this->routes(),
            $successRoute,
        );
    }
}
