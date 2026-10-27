<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\ResetPasswordCodeController;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCompleter;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
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

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'enabled'],
        ]);

        $controller = new ResetPasswordCodeController(
            $twig,
            $formFactory,
            new PasswordResetGate($profileRegistry),
            $tokenManager,
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $tokenManager,
                $profileRegistry,
            ),
            new TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'password_reset' => ['mode' => 'enabled'],
            ]),
        );

        self::assertSame('<html>error</html>', $controller->complete(new Request())->getContent());
    }

    /** SEC-004 M3: a client IP over code_rate_limit gets the invalid-code answer without any code check. */
    public function testCodeSubmissionsAreLimitedPerClientIp(): void
    {
        $tokenManager = $this->createMock(PasswordResetTokenManagerInterface::class);
        $tokenManager->expects(self::exactly(2))->method('resolveUserByIdentifierAndCode')->willReturn(null);

        $codeField = $this->createMock(FormInterface::class);
        $codeField->expects(self::exactly(3))->method('addError');
        $form = $this->createMock(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn(['identifier' => 'user@example.com', 'code' => '000000', 'password' => 'secret12']);
        $form->method('get')->with('code')->willReturn($codeField);
        $form->method('createView')->willReturn(new FormView());
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html>error</html>');

        $config          = ['password_reset' => ['mode' => 'enabled', 'code_rate_limit' => 2, 'code_rate_window' => 900]];
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, $config);
        $controller      = new ResetPasswordCodeController(
            $twig,
            $formFactory,
            new PasswordResetGate($profileRegistry),
            $tokenManager,
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $tokenManager,
                $profileRegistry,
            ),
            new TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            ProfileRegistryFactory::requestResolver(TestUser::class, $config),
            attemptLimiter: new AuthKitAttemptLimiter(new ArrayAdapter()),
        );

        foreach ([1, 2, 3] as $attempt) {
            $request = Request::create('/reset-password/complete', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
            self::assertSame('<html>error</html>', $controller->complete($request)->getContent(), (string) $attempt);
        }
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

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'enabled'],
        ]);

        $controller = new ResetPasswordCodeController(
            $this->createMock(Environment::class),
            $formFactory,
            new PasswordResetGate($profileRegistry),
            $tokenManager,
            new PasswordResetCompleter($entityManager, $hasher, new PropertyAccessor(), $tokenManager, $profileRegistry),
            new TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'password_reset' => ['mode' => 'enabled'],
            ]),
        );

        $response = $controller->complete($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('hashed', $user->getPassword());
    }

    public function testRedirectsWhenAuthenticated(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new TestUser(), 'main', ['ROLE_USER']));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/home');

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'enabled'],
        ]);

        $controller = new ResetPasswordCodeController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            new PasswordResetGate($profileRegistry),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $this->createMock(PasswordResetTokenManagerInterface::class),
                $profileRegistry,
            ),
            $storage,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'password_reset'      => ['mode' => 'enabled'],
                'login_success_route' => 'demo_home',
            ]),
        );

        $response = $controller->complete(new Request());

        self::assertSame('/home', $response->headers->get('Location'));
    }

    public function testRedirectsWhenDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->with('nowo_auth_kit_login')->willReturn('/login');

        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'disabled'],
        ]);

        $controller = new ResetPasswordCodeController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            new PasswordResetGate($profileRegistry),
            $this->createMock(PasswordResetTokenManagerInterface::class),
            new PasswordResetCompleter(
                $this->createMock(EntityManagerInterface::class),
                $this->createMock(UserPasswordHasherInterface::class),
                new PropertyAccessor(),
                $this->createMock(PasswordResetTokenManagerInterface::class),
                ProfileRegistryFactory::single(TestUser::class, [
                    'password_reset' => ['mode' => 'enabled'],
                ]),
            ),
            new TokenStorage(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'password_reset' => ['mode' => 'disabled'],
            ]),
        );

        self::assertSame('/login', $controller->complete(new Request())->headers->get('Location'));
    }
}
