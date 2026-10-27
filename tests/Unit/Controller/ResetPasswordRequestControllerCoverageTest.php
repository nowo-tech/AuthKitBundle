<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Controller\ResetPasswordRequestController;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\NullPasswordResetNotifier;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCheckEmail;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetThrottledException;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetUserResolver;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

final class ResetPasswordRequestControllerCoverageTest extends TestCase
{
    use AuthKitRoutesTrait;

    public function testSubmitsValidRequest(): void
    {
        $user       = new TestUser();
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willReturn(new PasswordResetTokenResult(
            $user,
            'token',
            new DateTimeImmutable('+1 hour'),
            PasswordResetDeliveryMode::Link,
        ));

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'enabled', 'delivery' => 'link'],
        ]);

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $profileRegistry),
            $tokenManager,
            new NullPasswordResetNotifier(),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            $this->createMock(EventDispatcherInterface::class),
            $profileRegistry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
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
            new PasswordResetGate($profileRegistry),
            $handler,
            new TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'password_reset' => ['mode' => 'enabled'],
            ]),
        );

        $response = $controller->request($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testAfterRequestRouteStoresTheCheckEmailPayload(): void
    {
        $user = new TestUser();
        $user->setEmail('user@example.com');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$user]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $availableAt  = new DateTimeImmutable('@1792760000');
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->method('createForUser')->willThrowException(new PasswordResetThrottledException($availableAt));

        $overrides       = ['password_reset' => ['mode' => 'enabled', 'token_ttl' => 1800, 'after_request_route' => 'app_check_email']];
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, $overrides);
        $handler         = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $profileRegistry),
            $tokenManager,
            new NullPasswordResetNotifier(),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            $this->createMock(EventDispatcherInterface::class),
            $profileRegistry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        $form = $this->createMock(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn(['identifier' => 'user@example.com']);
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->expects(self::once())->method('generate')->with('app_check_email')->willReturn('/check-email');

        $request = Request::create('/reset-password', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new ResetPasswordRequestController(
            $this->createMock(Environment::class),
            $formFactory,
            new PasswordResetGate($profileRegistry),
            $handler,
            new TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $overrides),
        );

        $response = $controller->request($request);

        self::assertSame('/check-email', $response->headers->get('Location'));
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);
        self::assertSame([], $session->getFlashBag()->peekAll());
        $checkEmail = PasswordResetCheckEmail::fromRequest($request);
        self::assertNotNull($checkEmail);
        self::assertSame(1800, $checkEmail->tokenTtl);
        self::assertTrue($checkEmail->throttled);
        self::assertSame(1792760000, $checkEmail->availableAt?->getTimestamp());
    }

    public function testRedirectsWhenAuthenticated(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new TestUser(), 'main', ['ROLE_USER']));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('demo_home')->willReturn('/home');

        $repository    = $this->createMock(EntityRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset'      => ['mode' => 'enabled', 'delivery' => 'link'],
            'login_success_route' => 'demo_home',
        ]);

        $handler = new PasswordResetRequestHandler(
            new PasswordResetUserResolver($entityManager, $profileRegistry),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            new NullPasswordResetNotifier(),
            AuthKitTestUrlGenerator::fromMock($inner),
            $this->createMock(EventDispatcherInterface::class),
            $profileRegistry,
            new AuthKitAttemptLimiter(new ArrayAdapter()),
            new RequestStack(),
        );

        $controller = new ResetPasswordRequestController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            new PasswordResetGate($profileRegistry),
            $handler,
            $storage,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'password_reset'      => ['mode' => 'enabled'],
                'login_success_route' => 'demo_home',
            ]),
        );

        self::assertSame('/home', $controller->request(new Request())->headers->get('Location'));
    }
}
