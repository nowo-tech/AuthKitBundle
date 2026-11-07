<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * One custom authenticator: worked before, and must not change.
 */
final class OnlyCustomKernelTest extends SignInKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return OnlyCustomKernel::class;
    }

    protected function expectedToken(): string
    {
        return PostAuthenticationToken::class;
    }
}
