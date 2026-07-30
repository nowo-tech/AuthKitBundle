<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
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

        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([]);

        $controller = new LoginController(
            $this->createMock(Environment::class),
            $this->createMock(FormFactoryInterface::class),
            $this->createMock(AuthenticationUtils::class),
            $tokenStorage,
            AuthKitTestUrlGenerator::fromMock($inner),
            new RegistrationGate(
                $this->createMock(EntityManagerInterface::class),
                ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'disabled']),
            ),
            new PasswordResetGate(ProfileRegistryFactory::single(TestUser::class)),
            new MagicLoginGate(ProfileRegistryFactory::single(TestUser::class)),
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class), $credentials),
            $credentials,
            ProfileRegistryFactory::requestResolver(TestUser::class, ['login_success_route' => 'demo_home']),
        );

        $response = $controller->login(new Request());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/home', $response->headers->get('Location'));
    }

    public function testRendersLoginTemplateWithRegistrationAllowedFlag(): void
    {
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => 'disabled']);

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new LoginFormType($profileRegistry, new PasswordFieldTypeResolver()))
            ->getFormFactory();

        $authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $authenticationUtils->method('getLastUsername')->willReturn('user@example.com');
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(null);

        $registrationGate = new RegistrationGate(
            $this->createMock(EntityManagerInterface::class),
            $profileRegistry,
        );

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                self::identicalTo($this->templates()['login']),
                self::callback(static function (array $context): bool {
                    return isset($context['registration_allowed'])
                        && $context['registration_allowed'] === false;
                }),
            )
            ->willReturn('<html>login</html>');

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([]);

        $controller = new LoginController(
            $twig,
            $formFactory,
            $authenticationUtils,
            $this->createMock(TokenStorageInterface::class),
            AuthKitTestUrlGenerator::fromMock($inner),
            $registrationGate,
            new PasswordResetGate($profileRegistry),
            new MagicLoginGate($profileRegistry),
            new SocialLoginGate($profileRegistry, $credentials),
            $credentials,
            ProfileRegistryFactory::requestResolver(TestUser::class, ['registration_mode' => 'disabled']),
        );

        $response = $controller->login(new Request());

        self::assertSame('<html>login</html>', $response->getContent());
    }

    public function testRendersSocialProvidersWhenEnabled(): void
    {
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, [
            'social_login' => ['mode' => 'enabled'],
        ]);

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new LoginFormType($profileRegistry, new PasswordFieldTypeResolver()))
            ->getFormFactory();

        $authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $authenticationUtils->method('getLastUsername')->willReturn('');
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(null);

        $provider = (new SocialLoginCredential())
            ->setProvider('google')
            ->setLabel('Google')
            ->setEnabled(true);

        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$provider]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                self::anything(),
                self::callback(static function (array $context) use ($provider): bool {
                    return ($context['social_login_enabled'] ?? false) === true
                        && ($context['social_login_providers'][0] ?? null) === $provider;
                }),
            )
            ->willReturn('<html>login</html>');

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $controller = new LoginController(
            $twig,
            $formFactory,
            $authenticationUtils,
            $this->createMock(TokenStorageInterface::class),
            AuthKitTestUrlGenerator::fromMock($inner),
            new RegistrationGate($this->createMock(EntityManagerInterface::class), $profileRegistry),
            new PasswordResetGate($profileRegistry),
            new MagicLoginGate($profileRegistry),
            new SocialLoginGate($profileRegistry, $credentials),
            $credentials,
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
        );

        self::assertSame('<html>login</html>', $controller->login(new Request())->getContent());
    }
}
