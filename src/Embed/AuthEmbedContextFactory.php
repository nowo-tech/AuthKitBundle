<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Embed;

use Nowo\AuthKitBundle\Enum\AuthEmbedMode;
use Nowo\AuthKitBundle\Enum\PasswordResetMode;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Form\SlideToConfirmTypeResolver;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\RegistrationGate;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Builds login/register form views for embedded auth UI (dropdown, etc.).
 */
final class AuthEmbedContextFactory
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly RegistrationGate $registrationGate,
        private readonly ProfileRegistry $profileRegistry,
        private readonly SlideToConfirmTypeResolver $slideToConfirmTypeResolver = new SlideToConfirmTypeResolver(),
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->profileRegistry->getDefault()->embed['mode'] === AuthEmbedMode::Dropdown->value;
    }

    /**
     * @param array<string, mixed>|AuthEmbedOptions $options
     */
    public function create(AuthEmbedOptions|array $options = []): ?AuthEmbedContext
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $opts    = $options instanceof AuthEmbedOptions ? $options : AuthEmbedOptions::fromArray($options);
        $profile = $this->resolveProfile($opts);
        $embed   = $profile->embed;

        $user            = $this->tokenStorage->getToken()?->getUser();
        $isAuthenticated = $user instanceof UserInterface;

        $registrationAllowed = $this->registrationGate->isRegistrationAllowed($profile->name);
        $showLogin           = $embed['show_login'];
        $showRegister        = $embed['show_register'] && $registrationAllowed;

        $loginForm = null;
        if (!$isAuthenticated && $showLogin) {
            $loginFormBuilder = $this->formFactory->create(LoginFormType::class, null, [
                'action'  => $this->urlGenerator->generate($profile->routes['login']['name']),
                'method'  => 'POST',
                'profile' => $profile->name,
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
                'action'  => $this->urlGenerator->generate($profile->routes['register']['name']),
                'method'  => 'POST',
                'profile' => $profile->name,
            ])->createView();
        }

        $activePanel = $opts->activePanel ?? 'login';
        if (!$showLogin && $showRegister) {
            $activePanel = 'register';
        }

        if ($showLogin && $this->authenticationUtils->getLastAuthenticationError() instanceof AuthenticationException) {
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
            loginRoute: $profile->routes['login']['name'],
            registerRoute: $profile->routes['register']['name'],
            logoutRoute: $profile->routes['logout']['name'],
            resetPasswordRoute: $profile->routes['reset_request']['name'],
            passwordResetEnabled: $profile->passwordReset['mode'] === PasswordResetMode::Enabled->value,
            activePanel: $activePanel,
            template: $opts->template ?? $embed['template'],
            loginPanelTemplate: $embed['login_panel'],
            registerPanelTemplate: $embed['register_panel'],
            authenticatedTemplate: $embed['authenticated'],
            options: $opts,
            slideToConfirmMode: $this->slideToConfirmTypeResolver->resolveRegistrationSlideMode(
                $profile->registrationFields,
                $profile->slideToConfirm,
            ),
        );
    }

    private function resolveProfile(AuthEmbedOptions $options): ProfileSettings
    {
        return $options->profile !== null
            ? $this->profileRegistry->getByName($options->profile)
            : $this->profileRegistry->getDefault();
    }
}
