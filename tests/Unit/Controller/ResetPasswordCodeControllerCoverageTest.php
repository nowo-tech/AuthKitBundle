<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\ResetPasswordCodeController;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
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
use Twig\Environment;

final class ResetPasswordCodeControllerCoverageTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testRendersFormWhenCodeIsInvalid(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('resolveUserByIdentifierAndCode')->willReturn(null);

        $codeField = $this->createMock(FormInterface::class);
        $codeField->expects(self::once())->method('addError');

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest');
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn([
            'identifier' => 'user@example.com',
            'code'       => '000000',
            'password'   => 'secret12',
        ]);
        $form->method('get')->with('code')->willReturn($codeField);
        $form->method('createView')->willReturn(new FormView());

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>error</html>');

        $controller = new ResetPasswordCodeController(
            $twig,
            $formFactory,
            new PasswordResetGate('enabled'),
            $tokenManager,
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $tokenManager,
                FieldConfigNormalizerFields::registration(),
            ),
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            $this->templates(),
            $this->routes(),
            null,
        );

        self::assertSame('<html>error</html>', $controller->complete(new Request())->getContent());
    }

    public function testCompletesWhenCodeIsValid(): void
    {
        $user         = new TestUser();
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('resolveUserByIdentifierAndCode')->willReturn($user);
        $tokenManager->expects(self::once())->method('clearForUser');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest');
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn([
            'identifier' => 'user@example.com',
            'code'       => '123456',
            'password'   => 'secret12',
        ]);

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $request = Request::create('/complete', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new ResetPasswordCodeController(
            $this->createMock(Environment::class),
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

        $response = $controller->complete($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('hashed', $user->getPassword());
    }

    public function testRedirectsWhenAuthenticated(): void
    {
        $storage = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $storage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new TestUser(), 'main', ['ROLE_USER']));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/home');

        $controller = new ResetPasswordCodeController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            new PasswordResetGate('enabled'),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $this->createMock(PasswordResetTokenManagerInterface::class),
                FieldConfigNormalizerFields::registration(),
            ),
            $storage,
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->templates(),
            $this->routes(),
            'demo_home',
        );

        $response = $controller->complete(new Request());

        self::assertSame('/home', $response->headers->get('Location'));
    }

    public function testRedirectsWhenDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $controller = new ResetPasswordCodeController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            new PasswordResetGate('disabled'),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $this->createMock(PasswordResetTokenManagerInterface::class),
                FieldConfigNormalizerFields::registration(),
            ),
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->templates(),
            $this->routes(),
            null,
        );

        self::assertSame('/login', $controller->complete(new Request())->headers->get('Location'));
    }
}
