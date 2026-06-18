<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\ResetPasswordController;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\ResetPasswordFormType;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

final class ResetPasswordControllerTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testRedirectsWhenTokenInvalid(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('resolveUserByLinkToken')->willReturn(null);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_reset_password_request')->willReturn('/reset-password');

        $controller = $this->controller($tokenManager, AuthKitTestUrlGenerator::fromMock($inner));

        $request  = Request::create('/reset-password/reset/bad');
        $response = $controller->reset($request, 'bad');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/reset-password', $response->headers->get('Location'));
    }

    public function testRendersResetFormForValidToken(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('resolveUserByLinkToken')->willReturn(new TestUser());

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>form</html>');

        $controller = $this->controller($tokenManager, AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)), $twig);

        $response = $controller->reset(new Request(), 'valid-token');

        self::assertSame('<html>form</html>', $response->getContent());
    }

    private function controller(
        PasswordResetTokenManagerInterface $tokenManager,
        AuthKitUrlGenerator $urlGenerator,
        ?Environment $twig = null,
    ): ResetPasswordController {
        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new ResetPasswordFormType(new PasswordFieldTypeResolver()))
            ->getFormFactory();

        $completer = new PasswordResetCompleter(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserPasswordHasherInterface::class),
            new PropertyAccessor(),
            $tokenManager,
            FieldConfigNormalizerFields::registration(),
        );

        return new ResetPasswordController(
            $twig ?? $this->createMock(Environment::class),
            $formFactory,
            new PasswordResetGate('enabled'),
            $tokenManager,
            $completer,
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            $urlGenerator,
            $this->templates(),
            $this->routes(),
            null,
        );
    }
}
