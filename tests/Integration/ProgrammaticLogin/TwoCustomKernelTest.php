<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

/**
 * Two host authenticators: AuthKit does not guess. Symfony's error stands, and nobody is signed in.
 */
final class TwoCustomKernelTest extends ProgrammaticLoginKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TwoCustomKernel::class;
    }

    public function testSocialFailsClosed(): void
    {
        $this->socialCallback();

        self::assertResponseStatusCodeSame(500);
        self::assertNull($this->token());
    }

    public function testQrFailsClosed(): void
    {
        $this->qrCompletion();

        self::assertResponseStatusCodeSame(500);
        self::assertNull($this->token());
    }
}
