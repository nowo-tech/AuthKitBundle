<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Embed;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Embed\AuthEmbedContext;
use Nowo\AuthKitBundle\Embed\AuthEmbedContextFactory;
use Nowo\AuthKitBundle\Embed\AuthEmbedOptions;
use Nowo\AuthKitBundle\Enum\AuthEmbedMode;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Controller\AuthKitRoutesTrait;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use Nowo\AuthKitBundle\Tests\Unit\Support\PasswordFieldResolvers;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Validation;

final class AuthEmbedContextFactoryTest extends TestCase
{
    use AuthKitRoutesTrait;

    /**
     * @param array<string, mixed> $profileOverrides
     */
    private function createFactory(
        array $profileOverrides,
        RegistrationGate $gate,
        ?AuthenticationUtils $authenticationUtils = null,
        ?TokenStorageInterface $tokenStorage = null,
        ?RequestStack $requestStack = null,
        ?Security $security = null,
    ): AuthEmbedContextFactory {
        $profileRegistry = ProfileRegistryFactory::single(TestUser::class, $profileOverrides);

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new LoginFormType($profileRegistry, PasswordFieldResolvers::typeResolver())))
            ->addType(FormKitTestSupport::withMerger(new RegistrationFormType(
                $profileRegistry,
                PasswordFieldResolvers::repeatedFieldBuilder(),
            )))
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
            $profileRegistry,
            requestStack: $requestStack,
            security: $security,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function embedConfig(string $mode = 'dropdown', bool $showLogin = true, bool $showRegister = true): array
    {
        return [
            'embed' => [
                'mode'           => $mode,
                'show_login'     => $showLogin,
                'show_register'  => $showRegister,
                'template'       => '@NowoAuthKitBundle/embed/dropdown.html.twig',
                'login_panel'    => '@NowoAuthKitBundle/embed/_login_panel.html.twig',
                'register_panel' => '@NowoAuthKitBundle/embed/_register_panel.html.twig',
                'authenticated'  => '@NowoAuthKitBundle/embed/_authenticated.html.twig',
            ],
        ];
    }

    private function registrationGate(string $mode = 'always'): RegistrationGate
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn($mode === 'first_user_only' ? 1 : 0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new RegistrationGate(
            $entityManager,
            ProfileRegistryFactory::single(TestUser::class, ['registration_mode' => $mode]),
        );
    }

    public function testDisabledModeReturnsNull(): void
    {
        $factory = $this->createFactory(
            $this->embedConfig(AuthEmbedMode::Disabled->value),
            $this->registrationGate(),
        );

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

        $context = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate(),
            tokenStorage: $tokenStorage,
        )->create();

        self::assertNotNull($context);
        self::assertTrue($context->isAuthenticated);
        self::assertSame('alice@example.com', $context->userIdentifier);
        self::assertNull($context->loginForm);
        self::assertNull($context->registrationForm);
    }

    public function testStaleTokenIsIgnoredOnNextRequestOutsideFirewallWithoutReset(): void
    {
        $alice = new TestUser();
        $alice->setEmail('alice@example.com');

        $tokenStorage = new TokenStorage();
        $requestStack = new RequestStack();
        $security     = $this->createMock(Security::class);
        $security->method('getFirewallConfig')->willReturnCallback(
            static fn (Request $request): FirewallConfig => new FirewallConfig('main', 'user_checker'),
        );

        $factory = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate(),
            tokenStorage: $tokenStorage,
            requestStack: $requestStack,
            security: $security,
        );

        // Request 1: behind the firewall, Alice is logged in.
        $first = Request::create('/account');
        $first->attributes->set('_firewall_context', 'security.firewall.map.context.main');
        $requestStack->push($first);
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', ['ROLE_USER']));

        $context = $factory->create();
        self::assertNotNull($context);
        self::assertTrue($context->isAuthenticated);
        self::assertSame('alice@example.com', $context->userIdentifier);
        $requestStack->pop();

        // Request 2: an anonymous visitor on a page outside any firewall; the token storage was not reset.
        $requestStack->push(Request::create('/public'));

        $context = $factory->create();
        self::assertNotNull($context);
        self::assertFalse($context->isAuthenticated);
        self::assertNull($context->userIdentifier);
        self::assertNotNull($context->loginForm);
    }

    public function testTokenIgnoredWhenFirewallHasSecurityDisabled(): void
    {
        $alice = new TestUser();
        $alice->setEmail('alice@example.com');

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', ['ROLE_USER']));

        $request = Request::create('/assets');
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.dev');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $security = $this->createMock(Security::class);
        $security->method('getFirewallConfig')->willReturn(new FirewallConfig('dev', 'user_checker', securityEnabled: false));

        $context = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate(),
            tokenStorage: $tokenStorage,
            requestStack: $requestStack,
            security: $security,
        )->create();

        self::assertNotNull($context);
        self::assertFalse($context->isAuthenticated);
    }

    public function testTokenIgnoredWithoutMainRequest(): void
    {
        $alice = new TestUser();
        $alice->setEmail('alice@example.com');

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', ['ROLE_USER']));

        $context = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate(),
            tokenStorage: $tokenStorage,
            requestStack: new RequestStack(),
        )->create();

        self::assertNotNull($context);
        self::assertFalse($context->isAuthenticated);
    }

    public function testTokenTrustedBehindFirewallWithoutSecurityService(): void
    {
        $alice = new TestUser();
        $alice->setEmail('alice@example.com');

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', ['ROLE_USER']));

        $request = Request::create('/account');
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $context = $this->createFactory(
            $this->embedConfig(),
            $this->registrationGate(),
            tokenStorage: $tokenStorage,
            requestStack: $requestStack,
        )->create();

        self::assertNotNull($context);
        self::assertTrue($context->isAuthenticated);
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
            array_replace_recursive($this->embedConfig(), [
                'password_reset' => ['mode' => 'enabled'],
            ]),
            $this->registrationGate(),
        )->create();

        self::assertNotNull($context);
        self::assertTrue($context->passwordResetEnabled);
        self::assertSame('nowo_auth_kit_reset_password_request', $context->resetPasswordRoute);
    }

    public function testCreateUsesNamedProfileFromOptions(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'default' => array_replace_recursive(
                ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
                $this->embedConfig(),
            ),
            'admin' => array_replace_recursive(
                ProfileRegistryFactory::defaultProfileConfig(TestUser::class),
                array_replace_recursive($this->embedConfig(), [
                    'embed' => ['show_register' => false],
                ]),
            ),
        ]);

        $gate = new RegistrationGate(
            $this->createMock(EntityManagerInterface::class),
            $registry,
        );

        $factory = new AuthEmbedContextFactory(
            Forms::createFormFactoryBuilder()
                ->addExtension(new ValidatorExtension(Validation::createValidator()))
                ->addType(FormKitTestSupport::withMerger(new LoginFormType($registry, PasswordFieldResolvers::typeResolver())))
                ->addType(FormKitTestSupport::withMerger(new RegistrationFormType(
                    $registry,
                    PasswordFieldResolvers::repeatedFieldBuilder(),
                )))
                ->getFormFactory(),
            $this->createMock(AuthenticationUtils::class),
            $this->createMock(TokenStorageInterface::class),
            AuthKitTestUrlGenerator::fromMock($this->createMock(UrlGeneratorInterface::class)),
            $gate,
            $registry,
        );

        $context = $factory->create(['profile' => 'admin']);

        self::assertNotNull($context);
        self::assertFalse($context->showRegister);
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
            options: new AuthEmbedOptions(formTheme: 'theme'),
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
            'slide_to_confirm_mode'   => null,
        ], $context->toArray());
    }
}
