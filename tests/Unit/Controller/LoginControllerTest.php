<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
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
    public function testRedirectsAuthenticatedUser(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken(new TestUser(), 'main', ['ROLE_USER']));

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('demo_home')->willReturn('/home');

        $controller = new LoginController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            $this->createMock(AuthenticationUtils::class),
            $tokenStorage,
            $urlGenerator,
            $this->templates(),
            $this->routes(),
            'demo_home',
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

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/login');

        $controller = new LoginController(
            $twig,
            $formFactory,
            $authenticationUtils,
            $this->createMock(TokenStorageInterface::class),
            $urlGenerator,
            $this->templates(),
            $this->routes(),
            null,
        );

        $response = $controller->login();

        self::assertSame('<html>login</html>', $response->getContent());
    }

    /**
     * @return array{layout: string, login: string, register: string}
     */
    private function templates(): array
    {
        return [
            'layout'   => '@NowoAuthKitBundle/layout.html.twig',
            'login'    => '@NowoAuthKitBundle/security/login.html.twig',
            'register' => '@NowoAuthKitBundle/security/register.html.twig',
        ];
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    private function routes(): array
    {
        return [
            'login'    => ['path' => '/login', 'name' => 'nowo_auth_kit_login'],
            'logout'   => ['path' => '/logout', 'name' => 'nowo_auth_kit_logout'],
            'register' => ['path' => '/register', 'name' => 'nowo_auth_kit_register'],
        ];
    }
}
