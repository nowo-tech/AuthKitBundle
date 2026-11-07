<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * One authenticator: worked before, and must not change.
 */
final class OnlyLoginLinkKernelTest extends SignInKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return OnlyLoginLinkKernel::class;
    }

    protected function expectedToken(): string
    {
        return PostAuthenticationToken::class;
    }
}
