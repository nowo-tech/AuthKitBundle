<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Embed;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Embed\AuthEmbedContext;
use Nowo\AuthKitBundle\Embed\AuthEmbedContextFactory;
use Nowo\AuthKitBundle\Enum\AuthEmbedMode;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Unit\Controller\AuthKitRoutesTrait;
use Nowo\AuthKitBundle\Tests\Unit\Controller\FieldConfigNormalizerFields;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Validation;

final class AuthEmbedContextFactoryTest extends TestCase
{
    use AuthKitRoutesTrait;

    /**
     * @return array{
     *     mode: string,
     *     show_login: bool,
     *     show_register: bool,
     *     template: string,
     *     login_panel: string,
     *     register_panel: string,
     *     authenticated: string
     * }
     */
    private function embedConfig(string $mode = 'dropdown', bool $showLogin = true, bool $showRegister = true): array
    {
        return [
            'mode'           => $mode,
            'show_login'     => $showLogin,
            'show_register'  => $showRegister,
            'template'       => '@NowoAuthKitBundle/embed/dropdown.html.twig',
            'login_panel'    => '@NowoAuthKitBundle/embed/_login_panel.html.twig',
            'register_panel' => '@NowoAuthKitBundle/embed/_register_panel.html.twig',
            'authenticated'  => '@NowoAuthKitBundle/embed/_authenticated.html.twig',
        ];
    }

    private function registrationGate(string $mode = 'always'): RegistrationGate
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn($mode === 'first_user_only' ? 1 : 0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new RegistrationGate($entityManager, TestUser::class, $mode);
    }

    /**
     * @param array{
     *     mode: string,
     *     show_login: bool,
     *     show_register: bool,
     *     template: string,
     *     login_panel: string,
     *     register_panel: string,
     *     authenticated: string
     * } $embed
     */
    private function createFactory(
        array $embed,
        RegistrationGate $gate,
        ?AuthenticationUtils $authenticationUtils = null,
        ?TokenStorageInterface $tokenStorage = null,
        string $passwordResetMode = 'disabled',
    ): AuthEmbedContextFactory {
        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new LoginFormType(FieldConfigNormalizerFields::login(), new PasswordFieldTypeResolver()))
            ->addType(new RegistrationFormType(FieldConfigNormalizerFields::registration(), new PasswordFieldTypeResolver()))
            ->getFormFactory();

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(
            static fn (string $name): string => '/' . str_replace('nowo_auth_kit_', '', $name),
        );

        return new AuthEmbedContextFactory(
            $formFactory,
            $authenticationUtils ?? $this->createMock(AuthenticationUtils::class),
            $tokenStorage ?? $this->createMock(TokenStorageInterface::class),
            AuthKitTestUrlGenerator::fromMock($inner),
            $gate,
            $this->routes(),
            $embed,
            $passwordResetMode,
        );
    }

    public function testDisabledModeReturnsNull(): void
    {
        $factory = $this->createFactory($this->embedConfig(AuthEmbedMode::Disabled->value), $this->registrationGate());

        self::assertFalse($factory->isEnabled());
        self::assertNull($factory->create());
    }

    public function testCreatesGuestContextWithForms(): void
    {
        $authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $authenticationUtils->method('getLastUsername')->willReturn('user@example.com');
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(null);

        $factory = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate(),
            authenticationUtils: $authenticationUtils,
        );
        $context = $factory->create(['form_theme' => 'form/theme.html.twig']);

        self::assertNotNull($context);
        self::assertFalse($context->isAuthenticated);
        self::assertTrue($context->showLogin);
        self::assertTrue($context->showRegister);
        self::assertNotNull($context->loginForm);
        self::assertNotNull($context->registrationForm);
        self::assertSame('login', $context->activePanel);
        self::assertSame('form/theme.html.twig', $context->toArray()['form_theme']);
        self::assertSame('/login', $context->loginForm->vars['action']);
    }

    public function testAuthenticatedContextOmitsForms(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $user         = new TestUser();
        $user->setEmail('alice@example.com');
        $tokenStorage->method('getToken')->willReturn(
            new UsernamePasswordToken($user, 'main', ['ROLE_USER']),
        );

        $context = $this->createFactory($this->embedConfig(), $this->registrationGate(), tokenStorage: $tokenStorage)->create();

        self::assertNotNull($context);
        self::assertTrue($context->isAuthenticated);
        self::assertSame('alice@example.com', $context->userIdentifier);
        self::assertNull($context->loginForm);
        self::assertNull($context->registrationForm);
    }

    public function testRegistrationHiddenWhenGateDisallows(): void
    {
        $context = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate('disabled'),
        )->create();

        self::assertNotNull($context);
        self::assertFalse($context->showRegister);
        self::assertNull($context->registrationForm);
    }

    public function testAuthErrorForcesLoginPanel(): void
    {
        $authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $authenticationUtils->method('getLastUsername')->willReturn('');
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(new BadCredentialsException());

        $context = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate(),
            authenticationUtils: $authenticationUtils,
        )->create(['active_panel' => 'register']);

        self::assertNotNull($context);
        self::assertSame('login', $context->activePanel);
        self::assertNotNull($context->error);
    }

    public function testRegisterOnlyUsesRegisterPanel(): void
    {
        $context = $this->createFactory(
            $this->embedConfig(showLogin: false),
            $this->registrationGate(),
        )->create();

        self::assertNotNull($context);
        self::assertFalse($context->showLogin);
        self::assertSame('register', $context->activePanel);
        self::assertNull($context->loginForm);
        self::assertNotNull($context->registrationForm);
    }

    public function testPasswordResetFlag(): void
    {
        $context = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate(),
            passwordResetMode: 'enabled',
        )->create();

        self::assertNotNull($context);
        self::assertTrue($context->passwordResetEnabled);
        self::assertSame('nowo_auth_kit_reset_password_request', $context->resetPasswordRoute);
    }

    public function testContextToArrayKeys(): void
    {
        $context = new AuthEmbedContext(
            isAuthenticated: false,
            userIdentifier: null,
            showLogin: true,
            showRegister: false,
            registrationAllowed: false,
            loginForm: null,
            registrationForm: null,
            error: null,
            loginRoute: 'login',
            registerRoute: 'register',
            logoutRoute: 'logout',
            resetPasswordRoute: 'reset',
            passwordResetEnabled: false,
            activePanel: 'login',
            template: 'tpl',
            loginPanelTemplate: 'login_tpl',
            registerPanelTemplate: 'register_tpl',
            authenticatedTemplate: 'auth_tpl',
            options: ['form_theme' => 'theme'],
        );

        self::assertSame([
            'is_authenticated'        => false,
            'user_identifier'         => null,
            'show_login'              => true,
            'show_register'           => false,
            'registration_allowed'    => false,
            'login_form'              => null,
            'registration_form'       => null,
            'error'                   => null,
            'login_route'             => 'login',
            'register_route'          => 'register',
            'logout_route'            => 'logout',
            'reset_password_route'    => 'reset',
            'password_reset_enabled'  => false,
            'active_panel'            => 'login',
            'login_panel_template'    => 'login_tpl',
            'register_panel_template' => 'register_tpl',
            'authenticated_template'  => 'auth_tpl',
            'form_theme'              => 'theme',
        ], $context->toArray());
    }
}
