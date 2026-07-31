<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Nowo\AuthKitBundle\QrLogin\NullQrCodeGenerator;
use PHPUnit\Framework\TestCase;

final class NullQrCodeGeneratorTest extends TestCase
{
    public function testReturnsNull(): void
    {
        $generator = new NullQrCodeGenerator();
        self::assertNull($generator->generateDataUri('https://example.com/approve?t=abc'));
    }
}
