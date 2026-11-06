<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

/**
 * GY-L4 control: with the mode enabled a signed link signs in.
 */
final class MagicLinkModeKernelTest extends MagicLinkKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MagicLinkTestKernel::class;
    }

    public function testASignedLinkSignsIn(): void
    {
        self::assertNotNull($this->useLink());
    }
}
