<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Embed;

use Nowo\AuthKitBundle\Enum\AuthEmbedMode;
use Nowo\AuthKitBundle\Enum\PasswordResetMode;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Builds login/register form views for embedded auth UI (dropdown, etc.).
 */
final class AuthEmbedContextFactory
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
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
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RegistrationGate $registrationGate,
        private readonly array $routes,
        private readonly array $embed,
        private readonly string $passwordResetMode,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->embed['mode'] === AuthEmbedMode::Dropdown->value;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function create(array $options = []): ?AuthEmbedContext
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $user            = $this->tokenStorage->getToken()?->getUser();
        $isAuthenticated = $user instanceof UserInterface;

        $registrationAllowed = $this->registrationGate->isRegistrationAllowed();
        $showLogin           = $this->embed['show_login'];
        $showRegister        = $this->embed['show_register'] && $registrationAllowed;

        $loginForm = null;
        if (!$isAuthenticated && $showLogin) {
            $loginFormBuilder = $this->formFactory->create(LoginFormType::class, null, [
                'action' => $this->urlGenerator->generate($this->routes['login']['name']),
                'method' => 'POST',
            ]);
            $lastUsername = $this->authenticationUtils->getLastUsername();
            if ($lastUsername !== '') {
                $loginFormBuilder->get('_username')->setData($lastUsername);
            }
            $loginForm = $loginFormBuilder->createView();
        }

        $registrationForm = null;
        if (!$isAuthenticated && $showRegister) {
            $registrationForm = $this->formFactory->create(RegistrationFormType::class, null, [
                'action' => $this->urlGenerator->generate($this->routes['register']['name']),
                'method' => 'POST',
            ])->createView();
        }

        $activePanel = $options['active_panel'] ?? 'login';
        if (!$showLogin && $showRegister) {
            $activePanel = 'register';
        }

        if ($showLogin && $this->authenticationUtils->getLastAuthenticationError() instanceof \Symfony\Component\Security\Core\Exception\AuthenticationException) {
            $activePanel = 'login';
        }

        return new AuthEmbedContext(
            isAuthenticated: $isAuthenticated,
            userIdentifier: $isAuthenticated ? $user->getUserIdentifier() : null,
            showLogin: $showLogin,
            showRegister: $showRegister,
            registrationAllowed: $registrationAllowed,
            loginForm: $loginForm,
            registrationForm: $registrationForm,
            error: $this->authenticationUtils->getLastAuthenticationError(),
            loginRoute: $this->routes['login']['name'],
            registerRoute: $this->routes['register']['name'],
            logoutRoute: $this->routes['logout']['name'],
            resetPasswordRoute: $this->routes['reset_request']['name'],
            passwordResetEnabled: $this->passwordResetMode === PasswordResetMode::Enabled->value,
            activePanel: $activePanel,
            template: $options['template'] ?? $this->embed['template'],
            loginPanelTemplate: $this->embed['login_panel'],
            registerPanelTemplate: $this->embed['register_panel'],
            authenticatedTemplate: $this->embed['authenticated'],
            options: $options,
        );
    }
}
