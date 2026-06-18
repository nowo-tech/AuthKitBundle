<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Controller\ResetPasswordRequestController;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\NullPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

final class ResetPasswordRequestControllerCoverageTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testSubmitsValidRequest(): void
    {
        $user       = new TestUser();
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn(new PasswordResetTokenResult(
            $user,
            'token',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Link,
        ));

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            $tokenManager,
            new NullPasswordResetNotifier(),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            $this->createMock(EventDispatcherInterface::class),
            $this->routes(),
            'link',
        );

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest');
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn(['identifier' => 'user@example.com']);
        $form->method('createView')->willReturn(new FormView());

        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $request = Request::create('/reset-password', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new ResetPasswordRequestController(
            $this->createMock(Environment::class),
            $formFactory,
            new PasswordResetGate('enabled'),
            $handler,
            new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->templates(),
            $this->routes(),
            null,
        );

        $response = $controller->request($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testRedirectsWhenAuthenticated(): void
    {
        $storage = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $storage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new TestUser(), 'main', ['ROLE_USER']));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('demo_home')->willReturn('/home');

        $repository    = $this->createMock(EntityRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        AuthKitTestUrlGenerator::fromMock($inner);

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, TestUser::class, 'email'),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            new NullPasswordResetNotifier(),
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->createMock(EventDispatcherInterface::class),
            $this->routes(),
            'link',
        );

        $controller = new ResetPasswordRequestController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            new PasswordResetGate('enabled'),
            $handler,
            $storage,
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->templates(),
            $this->routes(),
            'demo_home',
        );

        self::assertSame('/home', $controller->request(new Request())->headers->get('Location'));
    }
}
