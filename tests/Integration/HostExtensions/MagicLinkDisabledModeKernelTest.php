<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

/**
 * GY-L4: `magic_login.mode: disabled` stops a signed link on AuthKit's check route.
 */
final class MagicLinkDisabledModeKernelTest extends MagicLinkKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MagicLinkDisabledTestKernel::class;
    }

    public function testASignedLinkIsRefused(): void
    {
        self::assertNull($this->useLink());
    }
}
