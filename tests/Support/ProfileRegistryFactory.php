<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;

final class ProfileRegistryFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function single(string $userClass, array $overrides = [], string $profileName = 'default'): ProfileRegistry
    {
        return self::fromProfiles([
            $profileName => array_replace_recursive(self::defaultProfileConfig($userClass), $overrides),
        ], $profileName);
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    public static function fromProfiles(array $profiles, string $defaultProfileName = 'default'): ProfileRegistry
    {
        return new ProfileRegistry($profiles, $defaultProfileName);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function requestResolver(string $userClass, array $overrides = [], string $profileName = 'default'): RequestProfileResolver
    {
        return new RequestProfileResolver(self::single($userClass, $overrides, $profileName));
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultProfileConfig(string $userClass): array
    {
        return [
            'user_class'            => $userClass,
            'user_identifier_field' => 'email',
            'registration_role'     => 'ROLE_USER',
            'registration_mode'     => 'first_user_only',
            'login_fields'          => FieldConfigNormalizer::normalizeLoginFields(['identifier', 'password'], 'email'),
            'remember_me'           => ['enabled' => false, 'lifetime' => 604800, 'path' => '/'],
            'password_strength'     => ['enabled' => false, 'level' => 'medium', 'policy_mode' => 'level'],
            'registration_fields'   => FieldConfigNormalizer::normalizeRegistrationFields(['email', 'password']),
            'templates'             => [
                'layout'              => '@NowoAuthKitBundle/layout.html.twig',
                'login'               => '@NowoAuthKitBundle/security/login.html.twig',
                'register'            => '@NowoAuthKitBundle/security/register.html.twig',
                'reset_request'       => '@NowoAuthKitBundle/security/reset_request.html.twig',
                'reset_password'      => '@NowoAuthKitBundle/security/reset_password.html.twig',
                'reset_password_code' => '@NowoAuthKitBundle/security/reset_password_code.html.twig',
                'magic_login_request' => '@NowoAuthKitBundle/security/magic_login_request.html.twig',
                'magic_login_confirm' => '@NowoAuthKitBundle/security/magic_login_confirm.html.twig',
                'form_theme'          => ['@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'],
            ],
            'css' => [
                'button_class'           => 'nowo-auth-kit__button',
                'secondary_button_class' => 'nowo-auth-kit__social-button',
            ],
            'embed' => [
                'mode'           => 'disabled',
                'show_login'     => true,
                'show_register'  => true,
                'template'       => '@NowoAuthKitBundle/embed/dropdown.html.twig',
                'login_panel'    => '@NowoAuthKitBundle/embed/_login_panel.html.twig',
                'register_panel' => '@NowoAuthKitBundle/embed/_register_panel.html.twig',
                'authenticated'  => '@NowoAuthKitBundle/embed/_authenticated.html.twig',
            ],
            'password_reset' => [
                'mode'                => 'disabled',
                'delivery'            => 'link',
                'token_ttl'           => 3600,
                'token_bytes'         => 32,
                'code_length'         => 6,
                'code_charset'        => 'numeric',
                'max_code_attempts'   => 5,
                'request_rate_limit'  => 5,
                'request_rate_window' => 900,
                'token_field'         => 'passwordResetToken',
                'token_expires_field' => 'passwordResetExpiresAt',
            ],
            'magic_login' => [
                'mode'                 => 'disabled',
                'lifetime'             => 600,
                'max_uses'             => 1,
                'request_rate_limit'   => 5,
                'request_rate_window'  => 900,
                'confirm_interstitial' => false,
            ],
            'social_login' => [
                'mode'                   => 'disabled',
                'create_user_if_missing' => true,
                'require_verified_email' => true,
            ],
            'qr_login' => [
                'mode'                 => 'disabled',
                'challenge_ttl'        => 90,
                'poll_interval_ms'     => 1500,
                'approve_requires'     => 'session',
                'desktop_binding'      => 'strict',
                'phone_field'          => 'phone',
                'phone_verified_field' => 'phoneVerifiedAt',
                'create_rate_limit'    => 5,
                'create_rate_window'   => 600,
                'approve_rate_limit'   => 5,
            ],
            'registration_rate_limit'  => 5,
            'registration_rate_window' => 900,
            'routes'                   => [
                'login'               => ['path' => '/login', 'name' => 'nowo_auth_kit_login'],
                'logout'              => ['path' => '/logout', 'name' => 'nowo_auth_kit_logout'],
                'register'            => ['path' => '/register', 'name' => 'nowo_auth_kit_register'],
                'reset_request'       => ['path' => '/reset-password', 'name' => 'nowo_auth_kit_reset_password_request'],
                'reset_password'      => ['path' => '/reset-password/reset/{token}', 'name' => 'nowo_auth_kit_reset_password'],
                'reset_password_code' => ['path' => '/reset-password/complete', 'name' => 'nowo_auth_kit_reset_password_code'],
                'magic_login_request' => ['path' => '/magic-login', 'name' => 'nowo_auth_kit_magic_login_request'],
                'magic_login_check'   => ['path' => '/magic-login/check', 'name' => 'nowo_auth_kit_magic_login_check'],
                'social_login_start'  => ['path' => '/login/social/{provider}', 'name' => 'nowo_auth_kit_social_login_start'],
                'social_login_check'  => ['path' => '/login/social/{provider}/check', 'name' => 'nowo_auth_kit_social_login_check'],
                'qr_login_start'      => ['path' => '/login/qr', 'name' => 'nowo_auth_kit_qr_login_start'],
                'qr_login_show'       => ['path' => '/login/qr/{id}', 'name' => 'nowo_auth_kit_qr_login_show'],
                'qr_login_status'     => ['path' => '/login/qr/{id}/status', 'name' => 'nowo_auth_kit_qr_login_status'],
                'qr_login_complete'   => ['path' => '/login/qr/{id}/complete', 'name' => 'nowo_auth_kit_qr_login_complete'],
                'qr_login_approve'    => ['path' => '/login/qr/{id}/approve', 'name' => 'nowo_auth_kit_qr_login_approve'],
                'qr_login_deny'       => ['path' => '/login/qr/{id}/deny', 'name' => 'nowo_auth_kit_qr_login_deny'],
            ],
            'firewall'            => 'main',
            'login_success_route' => null,
        ];
    }
}
