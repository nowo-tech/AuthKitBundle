<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

/**
 * The magic link confirm page: GET shows the form, POST signs in through `MagicLoginConfirmController`.
 */
final class MagicConfirmKernel extends ProgrammaticLoginTestKernel
{
    protected function authenticators(): array
    {
        return [
            'form_login' => ['login_path' => 'nowo_auth_kit_login', 'check_path' => 'nowo_auth_kit_login', 'enable_csrf' => true],
            'login_link' => ['check_route' => 'nowo_auth_kit_magic_login_check', 'signature_properties' => ['email'], 'check_post_only' => true],
        ];
    }

    protected function extraConfig(): array
    {
        return ['magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true]];
    }
}
