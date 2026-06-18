<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

/**
 * Shared route map for controller tests.
 */
trait AuthKitRoutesTrait
{
    /**
     * @return array<string, array{path: string, name: string}>
     */
    protected function routes(): array
    {
        return [
            'login'               => ['path' => '/login', 'name' => 'nowo_auth_kit_login'],
            'logout'              => ['path' => '/logout', 'name' => 'nowo_auth_kit_logout'],
            'register'            => ['path' => '/register', 'name' => 'nowo_auth_kit_register'],
            'reset_request'       => ['path' => '/reset-password', 'name' => 'nowo_auth_kit_reset_password_request'],
            'reset_password'      => ['path' => '/reset-password/reset/{token}', 'name' => 'nowo_auth_kit_reset_password'],
            'reset_password_code' => ['path' => '/reset-password/complete', 'name' => 'nowo_auth_kit_reset_password_code'],
        ];
    }

    /**
     * @return array{layout: string, login: string, register: string, reset_request: string, reset_password: string, reset_password_code: string}
     */
    protected function templates(): array
    {
        return [
            'layout'              => '@NowoAuthKitBundle/layout.html.twig',
            'login'               => '@NowoAuthKitBundle/security/login.html.twig',
            'register'            => '@NowoAuthKitBundle/security/register.html.twig',
            'reset_request'       => '@NowoAuthKitBundle/security/reset_request.html.twig',
            'reset_password'      => '@NowoAuthKitBundle/security/reset_password.html.twig',
            'reset_password_code' => '@NowoAuthKitBundle/security/reset_password_code.html.twig',
        ];
    }
}
