<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MFA without CSRF protection does not compile (1.34, fail closed).
 */
final class MfaNoCsrfKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MfaNoCsrfTestKernel::class;
    }

    public function testTheContainerRefusesToCompile(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('profiles.default.mfa requires CSRF protection');
        self::bootKernel();
    }
}
