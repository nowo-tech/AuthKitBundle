<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Beacon's shape. Failed with "Too many authenticators" up to 1.37.0.
 */
final class FormLoginAndLoginLinkKernelTest extends SignInKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return FormLoginAndLoginLinkKernel::class;
    }

    protected function expectedToken(): string
    {
        return UsernamePasswordToken::class;
    }
}
