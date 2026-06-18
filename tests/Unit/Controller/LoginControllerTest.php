<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

final class LoginControllerTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testRedirectsAuthenticatedUser(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken(new TestUser(), 'main', ['ROLE_USER']));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('demo_home')->willReturn('/home');

        $controller = new LoginController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            $this->createMock(AuthenticationUtils::class),
            $tokenStorage,
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->templates(),
            $this->routes(),
            'demo_home',
            'disabled',
        );

        $response = $controller->login();

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/home', $response->headers->get('Location'));
    }

    public function testRendersLoginTemplate(): void
    {
        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new LoginFormType(FieldConfigNormalizerFields::login(), new PasswordFieldTypeResolver()))
            ->getFormFactory();

        $authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $authenticationUtils->method('getLastUsername')->willReturn('user@example.com');
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(null);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>login</html>');

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $controller = new LoginController(
            $twig,
            $formFactory,
            $authenticationUtils,
            $this->createMock(TokenStorageInterface::class),
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->templates(),
            $this->routes(),
            null,
            'disabled',
        );

        $response = $controller->login();

        self::assertSame('<html>login</html>', $response->getContent());
    }
}
