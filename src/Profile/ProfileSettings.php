<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Profile;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;

use function array_filter;
use function array_key_exists;
use function array_values;
use function is_array;
use function is_string;

final readonly class ProfileSettings
{
    private const LEGACY_LAYOUT = Configuration::LEGACY_LAYOUT;

    /**
     * @param class-string $userClass
     * @param list<array<string, mixed>> $loginFields
     * @param array<string, mixed> $rememberMe
     * @param array<string, mixed> $passwordStrength
     * @param array{enabled: bool, registration_consent: false|string, qr_login_approve: false|string} $slideToConfirm
     * @param array{enabled: bool, collect_on_auth_pages: bool, collect_endpoint: string, new_device_notify: bool, device_rate_limit: bool, qr_login: array{approve_require_trusted: bool}} $deviceIntelligence
     * @param array{enabled: bool, password_reset_code: bool} $otpInput
     * @param list<array<string, mixed>> $registrationFields
     * @param array<string, mixed> $templates
     * @param array{button_class: string, secondary_button_class: string} $css
     * @param array<string, mixed> $embed
     * @param array<string, mixed> $passwordReset
     * @param array<string, mixed> $magicLogin
     * @param array<string, mixed> $socialLogin
     * @param array<string, mixed> $qrLogin
     * @param array<string, array{path: string, name: string, methods?: list<string>}> $routes
     * @param array{layout_template?: ?string, templates?: array<string, string>, form_themes?: list<string>, brand?: ?string, qr_login?: array{stimulus_controller?: ?string}} $webUi page contract settings (REQ-UI-003)
     */
    public function __construct(
        public string $name,
        public string $userClass,
        public string $userIdentifierField,
        public string $registrationRole,
        public string $registrationMode,
        public array $loginFields,
        public array $rememberMe,
        public array $passwordStrength,
        public array $slideToConfirm,
        public array $deviceIntelligence,
        public array $otpInput,
        public array $registrationFields,
        public array $templates,
        public array $css,
        public array $embed,
        public array $passwordReset,
        public array $magicLogin,
        public array $socialLogin,
        public array $qrLogin,
        public array $routes,
        public string $firewall,
        public ?string $loginSuccessRoute,
        public int $registrationRateLimit = 5,
        public int $registrationRateWindow = 900,
        public array $webUi = [],
    ) {
    }

    /**
     * Template of an auth page: web_ui.templates.<page>, else the (deprecated) templates.<page> mirror, else the
     * bundle default.
     */
    public function template(string $page): string
    {
        $template = $this->webUi['templates'][$page] ?? $this->templates[$page] ?? null;

        return is_string($template) && $template !== '' ? $template : '@NowoAuthKitBundle/security/' . $page . '.html.twig';
    }

    /**
     * Layout override of the auth pages (AuthPage::layout): null = nowo_ui_kit.layouts.guest.
     */
    public function layoutTemplate(): ?string
    {
        if (array_key_exists('layout_template', $this->webUi)) {
            return $this->webUi['layout_template'];
        }
        $legacy = $this->templates['layout'] ?? null;

        return is_string($legacy) && $legacy !== '' && $legacy !== self::LEGACY_LAYOUT ? $legacy : null;
    }

    /**
     * Layout handed to 1.x page templates as `layout_template` ({% extends layout_template %}): the override, else
     * the deprecated bundle layout (a bridge to the UiKit guest layout).
     */
    public function legacyLayoutTemplate(): string
    {
        return $this->layoutTemplate() ?? self::LEGACY_LAYOUT;
    }

    /**
     * @return list<string>
     */
    public function formThemes(): array
    {
        $themes = $this->webUi['form_themes'] ?? $this->templates['form_theme'] ?? null;

        return is_array($themes) ? array_values(array_filter($themes, is_string(...))) : Configuration::defaultFormThemes();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        /** @var class-string $userClass */
        $userClass = $config['user_class'];

        return new self(
            name: $name,
            userClass: $userClass,
            userIdentifierField: $config['user_identifier_field'],
            registrationRole: $config['registration_role'],
            registrationMode: $config['registration_mode'],
            loginFields: $config['login_fields'],
            rememberMe: $config['remember_me'],
            passwordStrength: $config['password_strength'],
            slideToConfirm: $config['slide_to_confirm'] ?? [
                'enabled'              => false,
                'registration_consent' => 'gate',
                'qr_login_approve'     => false,
            ],
            deviceIntelligence: $config['device_intelligence'] ?? [
                'enabled'               => false,
                'collect_on_auth_pages' => true,
                'collect_endpoint'      => '/_device/collect',
                'new_device_notify'     => false,
                'device_rate_limit'     => false,
                'qr_login'              => ['approve_require_trusted' => false],
            ],
            otpInput: $config['otp_input'] ?? [
                'enabled'             => false,
                'password_reset_code' => true,
            ],
            registrationFields: $config['registration_fields'],
            templates: $config['templates'],
            css: $config['css'],
            embed: $config['embed'],
            passwordReset: $config['password_reset'],
            magicLogin: $config['magic_login'],
            socialLogin: $config['social_login'] ?? [
                'mode'                   => 'disabled',
                'create_user_if_missing' => true,
                'require_verified_email' => true,
                'pkce'                   => [],
            ],
            qrLogin: $config['qr_login'] ?? [
                'mode' => 'disabled',
            ],
            routes: $config['routes'],
            firewall: $config['firewall'],
            loginSuccessRoute: $config['login_success_route'],
            registrationRateLimit: (int) ($config['registration_rate_limit'] ?? 5),
            registrationRateWindow: (int) ($config['registration_rate_window'] ?? 900),
            webUi: $config['web_ui'] ?? [],
        );
    }
}
