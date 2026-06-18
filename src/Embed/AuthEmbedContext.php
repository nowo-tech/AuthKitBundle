<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Embed;

use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Variables passed to embedded auth Twig templates.
 */
final readonly class AuthEmbedContext
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public bool $isAuthenticated,
        public ?string $userIdentifier,
        public bool $showLogin,
        public bool $showRegister,
        public bool $registrationAllowed,
        public ?FormView $loginForm,
        public ?FormView $registrationForm,
        public ?AuthenticationException $error,
        public string $loginRoute,
        public string $registerRoute,
        public string $logoutRoute,
        public string $resetPasswordRoute,
        public bool $passwordResetEnabled,
        public string $activePanel,
        public string $template,
        public string $loginPanelTemplate,
        public string $registerPanelTemplate,
        public string $authenticatedTemplate,
        public array $options = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_authenticated'        => $this->isAuthenticated,
            'user_identifier'         => $this->userIdentifier,
            'show_login'              => $this->showLogin,
            'show_register'           => $this->showRegister,
            'registration_allowed'    => $this->registrationAllowed,
            'login_form'              => $this->loginForm,
            'registration_form'       => $this->registrationForm,
            'error'                   => $this->error,
            'login_route'             => $this->loginRoute,
            'register_route'          => $this->registerRoute,
            'logout_route'            => $this->logoutRoute,
            'reset_password_route'    => $this->resetPasswordRoute,
            'password_reset_enabled'  => $this->passwordResetEnabled,
            'active_panel'            => $this->activePanel,
            'login_panel_template'    => $this->loginPanelTemplate,
            'register_panel_template' => $this->registerPanelTemplate,
            'authenticated_template'  => $this->authenticatedTemplate,
            'form_theme'              => $this->options['form_theme'] ?? null,
        ];
    }
}
