<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Two authenticators without form_login: login_link, never the host's own.
 */
final class LoginLinkAndCustomKernelTest extends SignInKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return LoginLinkAndCustomKernel::class;
    }

    protected function expectedToken(): string
    {
        return PostAuthenticationToken::class;
    }
}
