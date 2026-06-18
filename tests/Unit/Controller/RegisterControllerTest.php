<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\RegisterController;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

final class RegisterControllerTest extends TestCase
{
    public function testRedirectsWhenUserAlreadyAuthenticated(): void
    {
        $user = new TestUser();
        $user->setEmail('logged@example.com');

        $tokenStorage = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('demo_home')->willReturn('/home');

        $controller = new RegisterController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            $this->registrationGateAllowed(),
            new UserRegistrar(
                TestUser::class,
                'ROLE_USER',
                FieldConfigNormalizerFields::registration(),
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
            ),
            $tokenStorage,
            $urlGenerator,
            $this->createMock(EventDispatcherInterface::class),
            $this->templates(),
            $this->routes(),
            'main',
            'demo_home',
        );

        $response = $controller->register(new Request());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/home', $response->headers->get('Location'));
    }

    public function testRedirectsWhenRegistrationDisabled(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $gate          = new RegistrationGate($entityManager, TestUser::class, 'disabled');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $controller = $this->createController($gate, $urlGenerator);

        $response = $controller->register(new Request());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testRendersRegistrationForm(): void
    {
        $gate = $this->registrationGateAllowed();

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>register</html>');

        $controller = $this->createController($gate, null, $twig);
        $response   = $controller->register(new Request());

        self::assertSame('<html>register</html>', $response->getContent());
    }

    public function testRegistersUserOnValidSubmission(): void
    {
        $gate = $this->registrationGateAllowed();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $registrar = new UserRegistrar(
            TestUser::class,
            'ROLE_USER',
            FieldConfigNormalizerFields::registration(),
            $entityManager,
            $hasher,
            new PropertyAccessor(),
        );

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::isInstanceOf(InteractiveLoginEvent::class));

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/home');

        $form = $this->createMock(\Symfony\Component\Form\FormInterface::class);
        $form->method('handleRequest');
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn([
            'email'    => 'new@example.com',
            'password' => 'secret12',
        ]);
        $form->method('createView')->willReturn($this->createMock(\Symfony\Component\Form\FormView::class));

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $controller = new RegisterController(
            $this->createMock(Environment::class),
            $formFactory,
            $gate,
            $registrar,
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            $urlGenerator,
            $dispatcher,
            $this->templates(),
            $this->routes(),
            'main',
            'demo_home',
        );

        $request = Request::create('/register', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $controller->register($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    private function registrationGateAllowed(): RegistrationGate
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository    = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('count')->willReturn(0);
        $entityManager->method('getRepository')->willReturn($repository);

        return new RegistrationGate($entityManager, TestUser::class, 'first_user_only');
    }

    private function createController(
        RegistrationGate $gate,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
    ): RegisterController {
        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new RegistrationFormType(FieldConfigNormalizerFields::registration(), new PasswordFieldTypeResolver()))
            ->getFormFactory();

        $entityManager = $this->createMock(EntityManagerInterface::class);

        return new RegisterController(
            $twig ?? $this->createMock(Environment::class),
            $formFactory,
            $gate,
            new UserRegistrar(
                TestUser::class,
                'ROLE_USER',
                FieldConfigNormalizerFields::registration(),
                $entityManager,
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
            ),
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            $urlGenerator ?? $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->templates(),
            $this->routes(),
            'main',
            'demo_home',
        );
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
