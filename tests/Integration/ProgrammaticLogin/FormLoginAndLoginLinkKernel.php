<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

final class FormLoginAndLoginLinkKernel extends ProgrammaticLoginTestKernel
{
    private const LOGIN_LINK_CONFIG = ['check_route' => 'nowo_auth_kit_magic_login_check', 'signature_properties' => ['email']];

    protected function authenticators(): array
    {
        return ['form_login' => ['login_path' => 'nowo_auth_kit_login', 'check_path' => 'nowo_auth_kit_login', 'enable_csrf' => true], 'login_link' => self::LOGIN_LINK_CONFIG];
    }

    protected function extraConfig(): array
    {
        return self::openAccountFlows();
    }
}
