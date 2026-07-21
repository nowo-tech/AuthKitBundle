<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use LogicException;
use Nowo\AuthKitBundle\Controller\MagicLoginCheckController;
use Nowo\AuthKitBundle\Controller\MagicLoginRequestController;
use Nowo\AuthKitBundle\Form\MagicLoginRequestFormType;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginRequestHandler;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginUserResolver;
use Nowo\AuthKitBundle\MagicLogin\NullMagicLoginNotifier;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

final class MagicLoginRequestControllerTest extends TestCase
{
    public function testRedirectsWhenMagicLoginDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $controller = $this->controller(
            new MagicLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'disabled'],
            ])),
            AuthKitTestUrlGenerator::fromMock($inner),
        );

        $response = $controller->request(new Request());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testRendersRequestForm(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html>magic</html>');

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled'],
        ]);

        $controller = $this->controller(
            new MagicLoginGate($profileRegistry),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            $twig,
            $profileRegistry,
        );

        $response = $controller->request(new Request());

        self::assertSame('<html>magic</html>', $response->getContent());
    }

    public function testRedirectsWhenAlreadyAuthenticated(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $tokenStorage = new TokenStorage();
        $user         = new TestUser();
        $user->setEmail('a@b.c');
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $controller = $this->controller(
            new MagicLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'magic_login' => ['mode' => 'enabled'],
            ])),
            AuthKitTestUrlGenerator::fromMock($inner),
            tokenStorage: $tokenStorage,
        );

        $response = $controller->request(new Request());
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testSubmitAddsFlashAndRedirects(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $user = new TestUser();
        $user->setEmail('user@example.com');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $loginLinkHandler = $this->createMock(LoginLinkHandlerInterface::class);
        $loginLinkHandler->method('createLoginLink')->willReturn(
            new LoginLinkDetails('https://example.test/check', new DateTimeImmutable('+5 minutes')),
        );

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled', 'lifetime' => 600, 'max_uses' => 1],
        ]);

        $requestStack = new RequestStack();
        $handler      = new MagicLoginRequestHandler(
            new MagicLoginUserResolver($entityManager, $profileRegistry),
            new NullMagicLoginNotifier(),
            $this->createMock(EventDispatcherInterface::class),
            $profileRegistry,
            $requestStack,
            $loginLinkHandler,
        );

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new MagicLoginRequestFormType($profileRegistry))
            ->getFormFactory();

        $controller = new MagicLoginRequestController(
            $this->createMock(Environment::class),
            $formFactory,
            new MagicLoginGate($profileRegistry),
            $handler,
            new TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'magic_login' => ['mode' => 'enabled', 'lifetime' => 600, 'max_uses' => 1],
            ]),
        );

        $form    = $formFactory->create(MagicLoginRequestFormType::class, null, ['profile' => 'default']);
        $request = Request::create('/magic-login', 'POST', [
            $form->getName() => ['identifier' => 'user@example.com'],
        ]);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $requestStack->push($request);

        $response = $controller->request($request);
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertTrue($session->getFlashBag()->has('success'));
    }

    public function testCheckControllerThrows(): void
    {
        $this->expectException(LogicException::class);
        (new MagicLoginCheckController())->check();
    }

    private function controller(
        MagicLoginGate $gate,
        AuthKitUrlGenerator $urlGenerator,
        ?Environment $twig = null,
        ?ProfileRegistry $profileRegistry = null,
        ?TokenStorage $tokenStorage = null,
    ): MagicLoginRequestController {
        $profileRegistry ??= ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled'],
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $handler = new MagicLoginRequestHandler(
            new MagicLoginUserResolver($entityManager, $profileRegistry),
            new NullMagicLoginNotifier(),
            $this->createMock(EventDispatcherInterface::class),
            $profileRegistry,
            new RequestStack(),
            $this->createMock(LoginLinkHandlerInterface::class),
        );

        return new MagicLoginRequestController(
            $twig ?? $this->createMock(Environment::class),
            Forms::createFormFactoryBuilder()
                ->addExtension(new HttpFoundationExtension())
                ->addExtension(new ValidatorExtension(Validation::createValidator()))
                ->addType(new MagicLoginRequestFormType($profileRegistry))
                ->getFormFactory(),
            $gate,
            $handler,
            $tokenStorage ?? new TokenStorage(),
            $urlGenerator,
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'magic_login' => $profileRegistry->getDefault()->magicLogin,
            ]),
        );
    }
}
